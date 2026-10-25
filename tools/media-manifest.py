#!/usr/bin/env python3
"""Builds docs/media-manifest.json from the old WordPress database (loaded locally as `tb`):
every uploaded file, its web address on the old site, and what used it (product photo, logo, category, page…).
Run: python3 tools/media-manifest.py   (needs the `mysql` client and the dump imported into database `tb`)."""
import json, re, subprocess, collections, os

def q(sql):
    out = subprocess.run(['mysql', '--default-character-set=utf8mb4', '-N', '-B', 'tb', '-e', sql], capture_output=True, text=True, check=True).stdout
    return [line.split('\t') for line in out.splitlines()]

base = 'https://tartebz.shop/wp-content/uploads/'
att = {}
for aid, title, mime, guid, parent, f in q("""SELECT p.ID, p.post_title, p.post_mime_type, p.guid, p.post_parent,
      (SELECT meta_value FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_wp_attached_file')
      FROM wp_posts p WHERE p.post_type='attachment'"""):
    path = f if f and f != 'NULL' else guid.split('/wp-content/uploads/')[-1]
    att[int(aid)] = {'id': int(aid), 'title': title, 'mime': mime, 'file': path, 'url': base + path, 'usedBy': []}

# WordPress keeps the full-size upload next to its "-scaled" copy (metadata original_image). Prefer the original.
for aid, meta in q("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key='_wp_attachment_metadata'"):
    m = re.search(r's:14:"original_image";s:\d+:"([^"]+)"', meta)
    a = att.get(int(aid))
    if a:
        w = re.search(r's:5:"width";i:(\d+);s:6:"height";i:(\d+);', meta)
        if w:
            a['width'], a['height'] = int(w.group(1)), int(w.group(2))
        if m:
            folder = a['file'].rsplit('/', 1)[0] + '/' if '/' in a['file'] else ''
            a['original'] = base + folder + m.group(1)

use = collections.defaultdict(list)
def mark(aid, what):
    try: aid = int(aid)
    except (TypeError, ValueError): return
    if aid in att and what not in att[aid]['usedBy']: att[aid]['usedBy'].append(what)

# Products: main photo and gallery (with the product name)
for pid, name, status, key, val in q("""SELECT p.ID, p.post_title, p.post_status, m.meta_key, m.meta_value FROM wp_posts p
      JOIN wp_postmeta m ON m.post_id=p.ID WHERE p.post_type='product' AND m.meta_key IN ('_thumbnail_id','_product_image_gallery')"""):
    label = f"product {pid} {name}" + (' (draft)' if status != 'publish' else '')
    for i, a in enumerate(val.split(',') if val else []):
        mark(a, f"{label} – {'main photo' if key == '_thumbnail_id' else 'gallery'}")
# Theme: logo, about image, site icon
mods = q("SELECT option_value FROM wp_options WHERE option_name='theme_mods_tartebz'")[0][0]
for k in ('custom_logo', 'tartebz_about_image', 'tartebz_hero_bg'):
    m = re.search(r's:%d:"%s";i:(\d+);' % (len(k), k), mods)
    if m: mark(m.group(1), {'custom_logo': 'site logo (current theme)', 'tartebz_about_image': 'About section image (current theme)', 'tartebz_hero_bg': 'hero background'}[k])
for (v,) in q("SELECT option_value FROM wp_options WHERE option_name='site_icon'"):
    mark(v, 'site icon (favicon)')
# Category thumbnails
for tid, name, aid in q("""SELECT t.term_id, t.name, tm.meta_value FROM wp_termmeta tm JOIN wp_terms t USING(term_id)
      WHERE tm.meta_key='thumbnail_id' AND tm.meta_value<>'0'"""):
    mark(aid, f"category {name}")
# Elementor pages and theme content referencing image ids or URLs
for pid, title, ptype, data in q("""SELECT p.ID, p.post_title, p.post_type, m.meta_value FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID
      WHERE m.meta_key='_elementor_data' AND p.post_status='publish' AND p.post_type IN ('page','elementor_library')"""):
    for m in re.finditer(r'"id":(\d+),"size"', data): mark(m.group(1), f"{ptype} {pid} {title} (Elementor)")
by_file = {a['file']: a for a in att.values()}
for row in q("""SELECT ID, post_title, post_content FROM wp_posts
      WHERE post_status='publish' AND post_type IN ('page','product','custom_css')"""):
    if len(row) < 3:
        continue
    pid, title, content = row[0], row[1], '\t'.join(row[2:])
    for m in re.finditer(r'wp-content/uploads/([^"\'\s)?]+)', content):
        f = re.sub(r'-\d+x\d+(\.\w+)$', r'\1', m.group(1))
        if f in by_file: mark(by_file[f]['id'], f"page {pid} {title} (content)")
# Elementor kit (old logo) and Smart Slider slides used on the live slider
kit = q("SELECT meta_value FROM wp_postmeta WHERE post_id=6931 AND meta_key='_elementor_page_settings'")
if kit:
    for m in re.finditer(r'"id";i:(\d+)', kit[0][0]): mark(m.group(1), 'old Elementor logo/favicon')

items = sorted(att.values(), key=lambda a: (not a['usedBy'], a['file']))
used = [a for a in items if a['usedBy']]
out = {'source': 'tartebz.shop WordPress database dump (2026-10-23)', 'total': len(items), 'used': len(used), 'files': items}
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.makedirs(os.path.join(root, 'docs'), exist_ok=True)
json.dump(out, open(os.path.join(root, 'docs/media-manifest.json'), 'w'), ensure_ascii=False, indent=1)
print(f"{len(items)} files, {len(used)} in use")
for a in used: print(f"  {a['file']}  <- {'; '.join(a['usedBy'])}")
