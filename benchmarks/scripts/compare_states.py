#!/usr/bin/env python3
"""Compare measurement-start states across independent strategy runs."""
import json
from pathlib import Path
import sys
from bench import audit_sequence_sum


def normalized(state):
    return {k:state[k] for k in ('tenants','record_sequence','token_count','audit_total','central_auth_audit')} | {'audit_sequence_total':audit_sequence_sum(state)}


def compare(paths):
    states=[normalized(json.loads(Path(p).read_text())) for p in paths]
    if len(states)<2 or any(s!=states[0] for s in states[1:]):
        raise RuntimeError('Measurement-start states are not comparable')
    return True


if __name__=='__main__':
    compare(sys.argv[1:]); print('Logical records, audit, token counts and normalized sequence effects match')
