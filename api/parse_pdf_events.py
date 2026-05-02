#!/usr/bin/env python3
"""
Parse UZH Alumni Informatik Jahresprogramm PDF and output events as JSON.

Uses coordinate-based column detection (pdfminer line-level layout) to
reconstruct the four-column table: Datum | Event | Ort | Bemerkungen.

Usage:
    python3 parse_pdf_events.py <path-to-pdf>

Output: JSON array compatible with events.json schema.
"""

import binascii
import json
import os
import re
import sys
from datetime import datetime, timedelta

try:
    from pdfminer.high_level import extract_pages
    from pdfminer.layout import LTTextBox, LTTextLine
except ImportError:
    print(json.dumps({"error": "pdfminer.six not installed – run: pip3 install pdfminer.six"}))
    sys.exit(1)

# Column x-boundaries derived from Jahresprogramm layout analysis
COL_DATUM_MAX = 154   # Datum:        x0 <= 154
COL_EVENT_MAX = 309   # Event:   155 <= x0 <= 309
COL_ORT_MAX   = 451   # Ort:     310 <= x0 <= 451
                       # Bemerkungen:  x0 >= 452 (not used for event data)

MONTH_MAP = {
    'Januar': 1, 'Februar': 2, 'März': 3, 'April': 4,
    'Mai': 5, 'Juni': 6, 'Juli': 7, 'August': 8,
    'September': 9, 'Oktober': 10, 'November': 11, 'Dezember': 12,
}

DAY_ABBREVS = {'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'}


def extract_lines(pdf_path):
    lines = []
    for page_layout in extract_pages(pdf_path):
        for element in page_layout:
            if isinstance(element, LTTextBox):
                for line in element:
                    if isinstance(line, LTTextLine):
                        text = line.get_text().strip()
                        if text:
                            lines.append({
                                'x0': round(line.x0),
                                'y0': round(line.y0),
                                'text': text,
                            })
    lines.sort(key=lambda l: (-l['y0'], l['x0']))
    return lines


def col(x0):
    if x0 <= COL_DATUM_MAX:
        return 'datum'
    if x0 <= COL_EVENT_MAX:
        return 'event'
    if x0 <= COL_ORT_MAX:
        return 'ort'
    return 'bemerkungen'


def is_date_anchor(text):
    first = text.split(',')[0].strip()
    if first in DAY_ABBREVS:
        return True
    if text.strip() in MONTH_MAP:
        return True
    if re.match(r'^(ca\.|Ende|Mitte|Anfang)\s', text.strip()):
        return True
    return False


def parse_datetime(date_text, time_text, year):
    m = re.search(r'(\d+)\.\s*(\w+)', date_text)
    if not m:
        return None, None
    day = int(m.group(1))
    month = MONTH_MAP.get(m.group(2))
    if not month:
        return None, None

    tm = re.search(r'(\d{1,2}):(\d{2})\s*[–\-]\s*(\d{1,2}):(\d{2})', time_text or '')
    if tm:
        sh, sm, eh, em = int(tm.group(1)), int(tm.group(2)), int(tm.group(3)), int(tm.group(4))
    else:
        sh, sm, eh, em = 18, 0, 21, 0

    try:
        start = datetime(year, month, day, sh, sm)
        end = datetime(year, month, day, eh, em)
        if end <= start:
            end += timedelta(days=1)
        tz = '+01:00'
        return start.strftime('%Y-%m-%dT%H:%M:00') + tz, end.strftime('%Y-%m-%dT%H:%M:00') + tz
    except ValueError:
        return None, None


def clean_location(parts):
    joined = ' '.join(parts).strip().rstrip(',')
    return joined if joined and joined.lower() != 'offen' else None


def parse_pdf(pdf_path):
    lines = extract_lines(pdf_path)

    # Year from header
    base_year = datetime.now().year
    for line in lines[:6]:
        m = re.search(r'\b(20\d{2})\b', line['text'])
        if m:
            base_year = int(m.group(1))
            break

    # Find date anchors, tracking year changes (e.g. "2027 – Ausblick")
    current_year = base_year
    date_anchors = []
    for line in lines:
        if line['x0'] > COL_DATUM_MAX:
            continue
        text = line['text']
        ym = re.match(r'^(20\d{2})\s*[–\-]', text)
        if ym:
            current_year = int(ym.group(1))
            continue
        if is_date_anchor(text):
            date_anchors.append({'y0': line['y0'], 'text': text, 'year': current_year})

    date_anchors.sort(key=lambda a: -a['y0'])

    events = []
    now_iso = datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%S') + '+00:00'

    for i, anchor in enumerate(date_anchors):
        top_y = anchor['y0']
        bot_y = date_anchors[i + 1]['y0'] if i + 1 < len(date_anchors) else 0

        group = [l for l in lines if bot_y < l['y0'] <= top_y]

        datum_lines = [l for l in group if col(l['x0']) == 'datum']
        event_lines = [l for l in group if col(l['x0']) == 'event']
        ort_lines   = [l for l in group if col(l['x0']) == 'ort']

        # Time: first datum-column line containing HH:MM
        time_text = ''
        for dl in datum_lines:
            if re.search(r'\d+:\d+', dl['text']):
                time_text = dl['text']
                break

        # Title: event column lines top-to-bottom
        title = ' '.join(l['text'] for l in sorted(event_lines, key=lambda l: -l['y0'])).strip()

        if not title or 'Readme' in title:
            continue

        # Skip rows without a precise day number
        if not re.search(r'\d+\.', anchor['text']):
            continue

        start_iso, end_iso = parse_datetime(anchor['text'], time_text, anchor['year'])
        if not start_iso:
            continue

        location = clean_location([l['text'] for l in sorted(ort_lines, key=lambda l: -l['y0'])])

        event_id = 'evt_' + start_iso[:10] + '_' + binascii.hexlify(os.urandom(4)).decode()

        events.append({
            'id': event_id,
            'title': title,
            'start': start_iso,
            'end': end_iso,
            'location': location,
            'image': None,
            'url': None,
            'description': None,
            'tags': [],
            'createdAt': now_iso,
        })

    return events


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Usage: parse_pdf_events.py <pdf-path>'}))
        sys.exit(1)
    pdf_path = sys.argv[1]
    if not os.path.isfile(pdf_path):
        print(json.dumps({'error': f'File not found: {pdf_path}'}))
        sys.exit(1)
    try:
        print(json.dumps(parse_pdf(pdf_path), ensure_ascii=False, indent=2))
    except Exception as exc:
        print(json.dumps({'error': str(exc)}))
        sys.exit(1)
