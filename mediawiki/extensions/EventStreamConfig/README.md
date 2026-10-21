# EventStreamConfig

This extension provides library functions and an API endpoint for exporting event stream configuration from the `$wgEventStreams` MediaWiki configuration variable.

This allows for centralized configuration of streams for both MediaWiki and external uses.

- The [EventLogging extension](T223931) uses this with [ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader) to load configs for streams used on certain pages to dynamically configure client stream settings, like sampling rate.
- Mobile apps use the API endpoint to dynamically configure client stream settings like sample rate.
- [EventGate](https://wikitech.wikimedia.org/wiki/Event_Platform/EventGate) event intake service(s) use this to ensure that only events of a specific schema title are allowed into a stream.
- [EventBus](https://www.mediawiki.org/wiki/Extension:EventBus) and other server side event producers uses this to figure out which event intake service a given stream should be produced to.

## Usage

### MediaWiki Config

`$wgEventStreams` is a list of individual stream configs. Each stream config must minimally specify its `schema_title` and its `stream` name settings. In `$wgEventStreams`, `stream` may either be a static stream name string, or a regex that matches stream names for which the stream config should be used. If using a regex, please keep them simple and performance safe. Config should be easy to understand and not add ReDOS vulnerabilities.

Example:

```php
$wgEventStreams = [
    [
        'stream' => 'test.event',
        'schema_title' => 'test/event',
        'sample' => [
            'rate' => 0.15,
        ],
        'canary_events_enabed' => true,
    ],
    [
        'stream' => 'nonya',
        'schema_title' => 'mediawiki/nonya',
        'sample' => [
            'rate' => 0.5,
        ],
    ],
    [
        'stream' => 'mediawiki.virtual_page_view',
        'schema_title' => 'mediawiki/page/virtual-view',
        'sample' => [
            'rate' => 0.1,
            'unit' => 'pageview',
        ],
    ],
    [
        'stream' => '/^mediawiki.edit(\..+)?/',
        'schema_title' => 'mediawiki/edit',
        'sample' => [
            'rate' => 0.8,
            'unit' => 'session',
        ],
    ],
];
```

### Getting configs for a list of streams

`StreamConfigs#get` takes a list of stream names to return configs for. The `$wgEventStreams` array is searched in order for the first `stream` that matches. The return value is a map from requested stream name to the matched stream config. By default any settings in StreamConfig::INTERNAL_SETTINGS are removed from the returned stream configs; as they are often not useful for client side configuration.\ The `$includeAllSettings` parameter disables this behavior.

Example:

```php
$streamConfigs = MediaWikiServices::getInstance()->getService('EventStreamConfig.StreamConfigs');

$streamConfigs->get( ['test.event', 'mediawiki.edit.cohort1'] );
# returns
[
    'test.event' => [
        'sample' => [
            'rate' => 0.15,
        ],
    ],
    'mediawiki.edit.cohort1' => [
        'sample' => [
            'rate' => 0.8,
            'unit' => 'session',
        ],
    ]
]
```

### streamconfig MW API endpoint:

```
curl http://wiki.domain.org/w/api.php?action=streamconfigs&format=json&streams=test.event|mediawiki.edit.cohort1
```

returns

```json
{
    "test.event": {
        "sample": {
            "rate": 0.15
        }
    },
    "mediawiki.edit.cohort1": {
        "sample": {
            "rate": 0.8,
            "unit": "session"
        }
    }
}
```

```
curl http://wiki.domain.org/w/api.php?action=streamconfigs&format=json&constraints=sample[rate]=0.15
```

returns

```json
{
    "test.event": {
        "sample": {
            "rate": 0.15
        }
    }
}
```


### Notes on settings constraints

When requesting stream configs via either the PHP or the HTTP API, you may provide an assoc array
of settings constraints with which to filter the stream configs. For example, if you want to only return
streams that have `schema_title == 'mediawiki/edit'`, then you can provide this as a constraint like so:

```php
$streamConfigs->get(
    null,
    /* $includeAllSettings = */ true,
    [
        'schema_title' => 'mediawiki/edit',
    ]
);
```

Further, constraints can be arbitrarily nested. Assuming that all streams published to by the
EventLogging JavaScript client have `producer[mwext_eventlogging] = true`, you can only return those
streams by providing a constraint like so:

```php
$streamConfigs->get(
    null,
    /* $includeAllSettings = */ true,
    [
        'producers' => [
            'mwext_eventlogging' => true,
        ]
    ]
);
```

When using the HTTP API, it isn't possible to know the proper type of an incoming constraint value, so
they are all provided as strings.  This is handled by casting stream configuration values to strings
before comparing them with the corresponding constraint value.  Be careful with boolean setting values
though, as PHP is weird:

```php
>>> (string)true
=> "1"
>>> (string)"true"
=> "true"
>>> (string)false
=> ""
>>> (string)"false"
=> "false"
```

So, when providing boolean constraints via the HTTP API, you should use the pre-casted string values
that PHP casts booleans to.  E.g. instead of `true`, use `"1"`, and instead of `false`, use `""`.

