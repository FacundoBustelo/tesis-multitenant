#!/usr/bin/env python3
"""Export operation samples without merging independent runs or averaging percentiles."""
import csv
import gzip
import json
from pathlib import Path
import sys


def export(folder):
    manifest=json.loads((folder/'manifest.json').read_text())
    with gzip.open(folder/'samples.json.gz','rt') as stream, (folder/'http.csv').open('w') as out:
        fields=['run','block','strategy','profile','metric','timestamp','operation','scenario','status','phase','window','value']
        writer=csv.DictWriter(out,fieldnames=fields); writer.writeheader()
        for line in stream:
            row=json.loads(line)
            if row.get('type')!='Point': continue
            data=row['data']; tags=data.get('tags',{})
            writer.writerow(dict(run=manifest['run'],block=manifest.get('arguments',{}).get('block',''),strategy=manifest['strategy'],profile=manifest.get('profile',''),metric=row['metric'],timestamp=data['time'],operation=tags.get('name',''),scenario=tags.get('scenario',''),status=tags.get('status',''),phase=tags.get('phase',''),window=tags.get('window',''),value=data['value']))
    with (folder/'resources.csv').open('w') as out:
        writer=csv.writer(out); writer.writerow(['timestamp','container','cpu','memory','block_io','network_io'])
        resource_path = folder/'resources.jsonl'
        for line in resource_path.read_text().splitlines() if resource_path.exists() else []:
            row=json.loads(line)
            for stat in row.get('docker','').splitlines():
                d=json.loads(stat); writer.writerow([row['utc'],d.get('Name'),d.get('CPUPerc'),d.get('MemUsage'),d.get('BlockIO'),d.get('NetIO')])


if __name__=='__main__': export(Path(sys.argv[1]))
