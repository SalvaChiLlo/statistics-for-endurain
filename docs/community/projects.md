# Community projects

This is a small, independent fork focused on Endurain, so it doesn't (yet) have its own list of community-built
add-ons. Several community projects exist for the upstream [Dreeve](https://github.com/robiningelbrecht/statistics-for-strava)
(formerly statistics-for-strava) project this fork is based on — worth checking there if you're looking for
tooling, keeping in mind they target Strava-based installs and may not be compatible with this fork's
Endurain-only setup and database schema.

> [!NOTE]
> **Note** These projects are not officially maintained by this fork. If you run into issues, please report them
> in the relevant repository.

## Use of Home Assistant weight-sensor

This guide is intended for users who:
- have [home-assistant.io](https://www.home-assistant.io) installed
- have a working weight sensor configured in Home Assistant

**Situation**: Home Assistant running as a Docker container with a scale
**Goal**: Automatically provide weight sensor readings to Dreeve

#### 1. Split SfS-config-file

Create a new file called `config-athlete-weight.yaml` in your `/config` directory:

```yaml
general:
  athlete:
    weightHistory:
      "YYYY-MM-DD": 100
```

#### 2. Update Docker Volume Mapping in Home Assistant

Make sure the SfS config directory is mounted inside your Home Assistant container. 
Add the following volume mapping to your `docker-compose.yml`:
```yaml
services:
  homeassistant:
    volumes:
      - /path/to/sfs/config:/sfsconfig
```


#### 3. Add Shell Command to home-assistant

Find your Home Assistant weight sensor (for example from a bluetooth scale, a fitness integration, etc.).
The entity will typically be named something like `sensor.weight_name`.

Add the following snippet to your `configuration.yaml.

This command checks if today's date already exists (to prevent duplicates) 
and then appends the value below `weightHistory`:.

```yaml
shell_command:
  update_sfs_weight: >
    grep -q '"{{ now().strftime("%Y-%m-%d") }}":' /sfsconfig/config-athlete-weight.yaml || sed -i '/weightHistory:/a \      "{{ now().strftime("%Y-%m-%d") }}": {{ states("sensor.weight_name") }}' /sfsconfig/config-athlete-weight.yaml

```

> [!NOTE]
> **Note** Ensure the 6 spaces after \ match the indentation used in your YAML file (see step 1).
> Also replace `sensor.weight_name` with your actual sensor entity.

#### 4. Create an automation in Home Assistant

Create a weekly automation to trigger the update. Example: every Monday at 15:00.

```yaml
alias: Weight Update
description: 
triggers:
  - at: "15:00:00"
    trigger: time
conditions:
  - condition: time
    weekday:
      - mon
  - condition: template
    value_template: "{{ is_number(states('sensor.weight_name')) }}"
actions:
  - action: shell_command.update_sfs_weight
mode: single
```

> [!NOTE]
> **Note** Replace `sensor.weight_name` with your actual sensor entity.
