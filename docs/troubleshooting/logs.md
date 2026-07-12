# Logs

In addition to the default Docker logs, the app maintains its own application logs.
These logs are stored in `storage/files/logs` with a 5-day rotation policy.
They can help diagnose and troubleshoot issues.

## CLI output logs

These logs capture all output from the CLI, providing a history of your imports and build processes.

### Example

```log
[2025-06-04T05:50:00.646195+00:00] console-output.INFO: Configuring locale... [] []
[2025-06-04T05:50:00.714520+00:00] console-output.INFO: Building Manifest... [] []
[2025-06-04T05:50:00.716234+00:00] console-output.INFO: Building App... [] []
[2025-06-04T05:50:00.716378+00:00] console-output.INFO:   => Building index [] []
[2025-06-04T05:50:02.059219+00:00] console-output.INFO:   => Building dashboard [] []
[2025-06-04T05:50:10.016846+00:00] console-output.INFO:   => Building activities [] []
[2025-06-04T05:50:19.658260+00:00] console-output.INFO:   => Building gpx files [] []
[2025-06-04T05:50:19.857651+00:00] console-output.INFO:   => Building monthly-stats [] []
[2025-06-04T05:50:21.074592+00:00] console-output.INFO:   => Building gear-stats [] []
[2025-06-04T05:50:21.140954+00:00] console-output.INFO:   => Building gear-maintenance [] []
[2025-06-04T05:50:21.182099+00:00] console-output.INFO:   => Building eddington [] []
[2025-06-04T05:50:31.956830+00:00] console-output.INFO:   => Building heatmap [] []
[2025-06-04T05:50:31.987428+00:00] console-output.INFO:   => Building rewind [] []
[2025-06-04T05:50:32.188401+00:00] console-output.INFO:   => Building photos [] []
[2025-06-04T05:50:32.359225+00:00] console-output.INFO:   => Building badges [] []
[2025-06-04T05:50:32.385393+00:00] console-output.INFO: <info>Time: 31.739s, Memory: 206.50 MB, Peak Memory: 212.50 MB</info> [] []
```

## Daemon logs

These logs capture all output from the Daemon running recurring background tasks.

```log
[2025-11-10T09:28:55.650390+00:00] daemon.INFO:   [] []
[2025-11-10T09:28:55.654875+00:00] daemon.INFO: <fg=black;bg=green>                                                                                                                        </> [] []
[2025-11-10T09:28:55.655126+00:00] daemon.INFO: <fg=black;bg=green> Dreeve v3.9.0 | DAEMON                                                                                  </> [] []
[2025-11-10T09:28:55.655243+00:00] daemon.INFO: <fg=black;bg=green>                                                                                                                        </> [] []
[2025-11-10T09:28:55.655363+00:00] daemon.INFO: <fg=black;bg=green> Started on 10-11-2025 10:28:55                                                                                         </> [] []
[2025-11-10T09:28:55.655504+00:00] daemon.INFO: <fg=black;bg=green>                                                                                                                        </> [] []
[2025-11-10T09:28:55.655637+00:00] daemon.INFO:   [] []
[2025-11-10T09:28:55.655723+00:00] daemon.INFO: <info>No cron items configured, shutting down cron...</info> [] []
```
