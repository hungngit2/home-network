#!/bin/bash
# Pull the latest image for one or more Docker containers and recreate them
# in place, preserving their existing ports/volumes/env/restart-policy.
# Usage: docker-upgrade.sh [container ...]   (default: all running containers)
set -euo pipefail

containers=("$@")
if [[ ${#containers[@]} -eq 0 ]]; then
	mapfile -t containers < <(docker ps --format '{{.Names}}')
fi

if [[ ${#containers[@]} -eq 0 ]]; then
	echo "No running containers found."
	exit 0
fi

echo "Upgrading: ${containers[*]}"
docker run --rm \
	-v /var/run/docker.sock:/var/run/docker.sock \
	containrrr/watchtower \
	--run-once \
	--cleanup \
	"${containers[@]}"
