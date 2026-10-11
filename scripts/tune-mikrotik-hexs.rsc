# hEX S hardening and cleanup from the 2026-10-11 audit (see MIKROTIK-HEXS.md). Safe to re-run.
# Apply:  scp scripts/tune-mikrotik-hexs.rsc admin@10.0.0.254:
#         ssh admin@10.0.0.254 '/import file-name=tune-mikrotik-hexs.rsc verbose=yes'
# The pre-change config is saved on the router as pre-tune.rsc for reference.

/export terse file=pre-tune

/ip service set api disabled=yes
/ip service set reverse-proxy disabled=yes
/tool bandwidth-server set enabled=no
/system ntp server set broadcast=no multicast=no manycast=no
/ip dns set mdns-repeat-ifaces=""
# detect-internet can add WAN interfaces to the `lan` list when it misjudges them; the wan list is static anyway
/interface detect-internet set detect-interface-list=none

# With established,related only, a port-forward's reply got the load-balancing mark and 1 in 5 left via LTE
/ip firewall mangle set [find comment~"^WAN [12] - In\$"] connection-state=new,established,related
/ipv6 firewall mangle set [find comment~"^WAN [12] - In\$"] connection-state=new,established,related

# The defconf drop only covers the `wan` list, so VPN-out, sfp1 and br-iptv could reach router services
:if ([/ip firewall filter print count-only where comment="Drop input from VPN-out"] = 0) do={
    /ip firewall filter add chain=input action=drop in-interface-list=vpn-out comment="Drop input from VPN-out" \
        place-before=[find comment="defconf: drop all not coming from LAN"]
    /ip firewall filter add chain=input action=drop in-interface=sfp1 comment="Drop input from modem link" \
        place-before=[find comment="defconf: drop all not coming from LAN"]
    /ip firewall filter add chain=input action=drop in-interface=br-iptv protocol=tcp comment="Drop TCP from IPTV" \
        place-before=[find comment="defconf: drop all not coming from LAN"]
    /ip firewall filter add chain=input action=drop in-interface=br-iptv protocol=udp dst-port=53,123,161,5678 \
        comment="Drop router services from IPTV" place-before=[find where comment="IPTV Stream" and protocol="udp"]
    /ip firewall filter add chain=forward action=drop connection-state=new connection-nat-state=!dstnat \
        in-interface-list=vpn-out comment="Drop new from VPN-out not DSTNATed" \
        place-before=[find comment="Block Remote Site to Local Network"]
}

# An AP that reboots weekly was STP root: netifd's default 0x7fff beats RouterOS's 0x8000
/interface bridge set br-lan priority=0x1000

# Each bridge has its own ND entry; the default one also advertised on WAN and VPN interfaces
/ipv6 nd set [find default=yes] disabled=yes

/ip dhcp-server lease remove [find disabled=yes]
/ip address remove [find comment="Test MSM8916 IP"]
/interface list member remove [find list=lan interface=*25]
/ip firewall mangle remove [find comment="To VPN"]
/ip firewall address-list remove [find list=vpn-routing-sites]
:if ([/ip route print count-only where routing-table=to-wan2-f] = 0) do={ /routing table remove [find name=to-wan2-f] }

:put "tune-mikrotik-hexs: done"
