# Proxmox

A lot of people use [Proxmox](https://www.proxmox.com/en/) to drive their self-hosted environment.
This guide will help you set up the app on a Proxmox virtual machine.

> [!IMPORTANT]
> Thanks to <a href="https://github.com/apachelance" target="_blank">apachelance</a> for creating the original version of this guide and sharing it with the community.

> [!NOTE]
> **Note** Depending on your docker installation or plugin, you may need to use either _docker compose_ or _docker-compose_.

### Create a privileged Proxmox container using the UI

* 1 CPU, 512 MB RAM, 8-10GB storage, fixed IP4 address
* Add root credentials
* Under Templates, choose Debian OS

### Install Docker in the container

open the container console, login as `root` and execute:

```bash
apt update && apt upgrade -y
apt install docker.io docker-compose -y
systemctl enable docker
```

### Create a directory

```bash
mkdir -p /opt/statistics-for-endurain
cd /opt/statistics-for-endurain
```

Now follow the instructions on the [installation page](/getting-started/installation.md) to set up the app,
including the required `APP_SECRET`, `APP_URL` and Endurain service-account variables in your `.env` file.

### Permissions

Stop the container in your Proxmox UI and open the console of the proxmox host (not the app container!).
Now you need to modify the container configuration file.
Please choose the file according to your Proxmox container ID (e.g. `110.conf` or `104.conf`)

```bash
nano /etc/pve/lxc/110.conf
```

Add these lines to the end of the file:

```
lxc.apparmor.profile: unconfined
lxc.cap.drop:
lxc.cgroup2.devices.allow: a
lxc.mount.auto: proc:rw sys:rw
lxc.mount.entry: /dev/fuse dev/fuse none bind,create=file
lxc.apparmor.allow_nesting: 1
```

### Restart the container

* Start your container using the Proxmox GUI
* Enter the console of the container and start docker

```bash
docker-compose up -d
```

### Final steps

Once the container is up and your `.env` points at your Endurain instance and service account, recreate it
to pick up any further `.env` changes:

```bash
docker-compose up -d --force-recreate
```

> [!TIP]
> You're all set :partying_face:! You can now <a href="/#/getting-started/installation?id=import-and-build-statistics">import and build</a> your statistics.
