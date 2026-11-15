#!/usr/bin/env python3
"""Deliver the new wiki's web readiness over a same-origin event stream."""
import asyncio
from hashlib import sha256
import json
import os
import re

from aiohttp import ClientError, ClientSession, ClientTimeout, web
from redis.asyncio import Redis
from redis.exceptions import RedisError


TICKET_PREFIX = 'global:farm:creation-ticket:'
STATUS_PREFIX = 'global:farm:provision-status:'
CHANNEL_PREFIX = 'global:farm:creation-complete:'
PROBE_URL = 'http://web:8083/wiki-creation/probe'


async def wiki_is_reachable(http, ticket):
    try:
        async with http.get(PROBE_URL, headers={'Host': ticket['host']},
                            allow_redirects=False) as response:
            if response.status != 200:
                return False
            data = await response.json()
    except (ClientError, asyncio.TimeoutError, ValueError):
        return False
    if ticket['private']:
        return data.get('error', {}).get('code') == 'readapidenied'
    return data.get('query', {}).get('general', {}).get('wikiid') == ticket['wiki']


async def wait_for_web(http, ticket):
    # The worker and FPM have separate metadata caches. Retry briefly after the
    # event, even when the wiki has finished provisioning on the first check.
    deadline = asyncio.get_running_loop().time() + 15
    while not await wiki_is_reachable(http, ticket):
        if asyncio.get_running_loop().time() >= deadline:
            return False
        await asyncio.sleep(1)
    return True


async def creation_events(request):
    token = request.query.get('ticket', '')
    if not re.fullmatch('[a-f0-9]{64}', token):
        raise web.HTTPNotFound()
    redis = request.app['redis']
    raw_ticket = await redis.get(TICKET_PREFIX + sha256(token.encode()).hexdigest())
    if not raw_ticket:
        raise web.HTTPNotFound()
    ticket = json.loads(raw_ticket)
    wiki, event = ticket['wiki'], ticket['event']
    channel = CHANNEL_PREFIX + wiki
    status_key = STATUS_PREFIX + wiki

    response = web.StreamResponse(headers={
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-store, no-transform',
    })
    await response.prepare(request)
    try:
        await response.write(b'retry: 2000\n: connected\n\n')
        async with redis.pubsub() as subscriber:
            await subscriber.subscribe(channel)
            # get_message returns the subscription acknowledgment. A status read
            # after it covers completion both before and during subscription.
            while True:
                message = await subscriber.get_message(timeout=10)
                if message is None:
                    await response.write(b': keepalive\n\n')
                    continue
                if message['type'] != 'subscribe' and message['data'] != event:
                    continue
                state, current_event, ready = await redis.hmget(status_key, 'state', 'event', 'web_ready')
                if current_event != event:
                    await response.write(b'event: expired\ndata: {}\n\n')
                    break
                if state != 'complete':
                    continue
                if ready == event or await wait_for_web(request.app['http'], ticket):
                    # Store readiness for reconnects, scoped to this creation event.
                    stored = await redis.eval('''
                        if redis.call('HGET', KEYS[1], 'event') ~= ARGV[1] then return 0 end
                        redis.call('HSET', KEYS[1], 'web_ready', ARGV[1])
                        return 1
                    ''', 1, status_key, event)
                    await response.write(b'event: ready\ndata: {}\n\n' if stored
                                         else b'event: expired\ndata: {}\n\n')
                # If FPM still needs time, EventSource reconnects and rechecks.
                break
    except (ConnectionError, asyncio.TimeoutError, RedisError):
        pass  # The browser reconnects and reads the stored status again.
    return response


async def dependencies(app):
    host, port = os.environ.get('MW_REDIS', 'redis:6379').rsplit(':', 1)
    async with Redis(host=host, port=int(port), decode_responses=True, socket_connect_timeout=3) as redis:
        async with ClientSession(timeout=ClientTimeout(total=3)) as http:
            app['redis'] = redis
            app['http'] = http
            yield


async def health(request):
    await request.app['redis'].ping()
    return web.Response(status=204)


def create_app():
    app = web.Application()
    app.cleanup_ctx.append(dependencies)
    app.router.add_get('/wiki-creation/events', creation_events, allow_head=False)
    app.router.add_get('/health', health)
    return app


if __name__ == '__main__':
    # The query string contains a bearer ticket, so omit request URLs from logs.
    web.run_app(create_app(), port=8080, access_log=None)
