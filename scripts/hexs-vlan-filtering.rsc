# Move IoT (VLAN 10) and Guest (VLAN 12) from per-port VLANs + br-iot/br-guest onto br-lan with VLAN filtering.
# Fixes RouterOS 7.25's "vlan parent is a slave" on vlan-e1.10, vlan-e1.12, vlan-e2.10, vlan-e2.12, vlan-e5.10.
# Apply:  scp scripts/hexs-vlan-filtering.rsc admin@10.0.0.254:
#         ssh admin@10.0.0.254 '/import file-name=hexs-vlan-filtering.rsc verbose=yes'
# Saves pre-vlan-filtering.rsc and pre-vlan-filtering.backup on the router first.
# Applied live in Nov 2026. Not idempotent: a second run duplicates the VLAN interfaces and bridge VLANs.

/export terse file=pre-vlan-filtering
/system backup save name=pre-vlan-filtering dont-encrypt=yes

/interface vlan add interface=br-lan vlan-id=10 name=vlan-iot comment="IoT"
/interface vlan add interface=br-lan vlan-id=12 name=vlan-guest comment="Guest"
/interface bridge vlan add bridge=br-lan vlan-ids=10 tagged=br-lan,ether1,ether2,ether5 comment="IoT"
/interface bridge vlan add bridge=br-lan vlan-ids=12 tagged=br-lan,ether1,ether2 comment="Guest"

/ip address set [find interface=br-iot] interface=vlan-iot
/ip address set [find interface=br-guest] interface=vlan-guest
/ip dhcp-server set [find interface=br-iot] interface=vlan-iot
/ip dhcp-server set [find interface=br-guest] interface=vlan-guest
/ipv6 address set [find interface=br-iot and !dynamic] interface=vlan-iot
/ipv6 address set [find interface=br-guest and !dynamic] interface=vlan-guest
/ipv6 nd set [find interface=br-iot] interface=vlan-iot
/ipv6 nd set [find interface=br-guest] interface=vlan-guest
/interface list member set [find interface=br-iot] interface=vlan-iot
/interface list member set [find interface=br-guest] interface=vlan-guest
/ip firewall filter set [find in-interface=br-iot] in-interface=vlan-iot
/ip firewall filter set [find in-interface=br-guest] in-interface=vlan-guest
/ipv6 firewall filter set [find in-interface=br-iot] in-interface=vlan-iot
/ipv6 firewall filter set [find in-interface=br-guest] in-interface=vlan-guest

/interface bridge port remove [find bridge=br-iot or bridge=br-guest]
/interface vlan remove [find name~"^vlan-e"]
/interface bridge remove [find name=br-iot or name=br-guest]

# Dead-man switch: remove it once SSH still works, or filtering turns itself off after 3 minutes
/system scheduler add name=vf-revert interval=3m on-event="/interface bridge set br-lan vlan-filtering=no; /system scheduler remove vf-revert"
/interface bridge set br-lan vlan-filtering=yes
