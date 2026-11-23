# CloudWiki operations dashboards

Provisioned dashboards: Overview (home), Host, Containers, Availability.
Generate JSON with `python3 docker/monitoring/build_dashboards.py`.
Test the snapshot adapter with `python3 -m unittest discover -s docker/monitoring/tests -v`.

Deployment requires the existing monitoring stack and the official Blackbox image already downloaded as `prom/blackbox-exporter:latest`; install.py resolves it to an immutable registry digest. Transfer this directory to the server, then run install.py with sudo. It validates configuration and backs up /opt/cloudwiki-monitoring first. It refuses a second installation to avoid silently replacing reviewed live settings.

The root-owned collector reads only GET /snapshot on the existing admin-agent Unix socket. It publishes bounded service gauges to /var/lib/cloudwiki-monitoring/textfile/services.prom by atomic replacement every 15 seconds. Node Exporter reads that directory read-only. No Docker socket, admin operations, new credentials or public collection endpoints are added.

Custom metrics:
- cloudwiki_collector_success: 1 for fresh error-free service data; otherwise 0.
- cloudwiki_snapshot_timestamp_seconds: source snapshot time, or 0 when unavailable.
- cloudwiki_collection_timestamp_seconds: collector run time.
- cloudwiki_service_state: running/active 1, stopped 0, missing -1, unknown -2, ambiguous -3.
- cloudwiki_service_health: healthy 1, unhealthy 0, starting 2, not configured -1, unknown -2.
- cloudwiki_service_cpu_percent, memory_bytes, uptime_seconds, restarts: gauges; labels service and kind (container/unit). CPU 100 means one core. Restart count resets on recreation. Null/nonfinite/negative measurements are omitted. Service gauges are omitted after 60 seconds of source staleness. Dashboards also gate freshness in case the collector stops.

Collection failures and stale/missing values are Unknown, never healthy. Containers without a Docker health check show Not configured. Availability is sampled from this VPS through Cloudflare, not from an independent external monitor. It cannot detect a total monitoring-server outage independently. Observed availability excludes missing samples and begins at rollout. Probe failures include incorrect page titles, Wiki not found, bad TLS, non-200 final responses, and timeouts. No login credentials are sent by probes.

Host warning/critical colors: CPU and memory 85/95 percent; disk 80/90 percent. No alert rules or notification channels are created. Keep current seven-day/1GiB retention and existing Grafana/Prometheus memory limits.

Operate with `sudo docker compose -f /opt/cloudwiki-monitoring/compose.json ps` and `sudo systemctl status cloudwiki-monitoring-collector.timer`. Inspect the collector with journalctl and curl Prometheus's private API. Blackbox listens on 127.0.0.1:9115.

Rollback: read the exact backup path from dashboard-rollout.json. Disable and stop cloudwiki-monitoring-collector.timer and stop its service. Restore the backed-up compose.json, prometheus.json, dashboard directory and Grafana provisioning settings into /opt/cloudwiki-monitoring, preserving the backup's ownership/modes. Remove the collector unit files and daemon-reload. Run the restored compose file with `up -d --remove-orphans`. Do not delete Docker volumes. The backup contains private bootstrap credentials and must remain root-only.

Validated deployment (November 22, 2026, America/Vancouver): four dashboards rendered in Grafana 13.3.1; interactive multi-service selection verified; all six scrape targets healthy, 13 container and three host-unit series present, and all three public probes successful. All dashboard PromQL expressions returned data. Nine fixture tests passed. New collector peak memory approximately 13MiB and Blackbox approximately 16MiB; root filesystem had 6.6GiB available. Pre-rollout backup: /var/lib/cloudwiki-monitoring/backups/20261123T011534Z (UTC filename).

Run the deployed read-only acceptance checks with `sudo python3 /opt/cloudwiki-monitoring/verify-dashboards.py`. Public probes intentionally require the expected title; update blackbox.json and regenerate it from build_dashboards.py if site branding changes. The shipped verify.py can be transferred separately for future checks.
