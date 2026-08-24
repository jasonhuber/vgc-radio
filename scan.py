"""One-off BLE discovery: find the VR-N76 and confirm it advertises the
Benshi radio GATT service. macOS does not expose BLE MAC addresses to
userspace, so bleak reports a CoreBluetooth peripheral UUID instead --
that UUID, not the 38:D2:... Classic MAC, is what benlink's new_ble() wants.
"""
import asyncio
from bleak import BleakScanner

RADIO_SERVICE_UUID = "00001100-d102-11e1-9b23-00025b00a5a5"


async def main():
    print("Scanning 10s for BLE devices...\n")
    found = await BleakScanner.discover(timeout=10.0, return_adv=True)
    for dev, adv in found.values():
        name = dev.name or adv.local_name or ""
        svcs = [s.lower() for s in adv.service_uuids]
        hit = RADIO_SERVICE_UUID in svcs
        if hit or "n76" in name.lower() or "vgc" in name.lower():
            print(f"  *** MATCH: {name!r}")
            print(f"      identifier: {dev.address}")
            print(f"      rssi:       {adv.rssi} dBm")
            print(f"      services:   {svcs or '(none advertised)'}")
            print(f"      radio svc advertised: {hit}\n")
    print(f"Total BLE devices seen: {len(found)}")
    named = [(d.name or a.local_name, d.address) for d, a in found.values() if (d.name or a.local_name)]
    print(f"Named devices: {named}")


asyncio.run(main())
