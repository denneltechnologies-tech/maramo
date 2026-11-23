import re, urllib.request, pathlib, hashlib

base = pathlib.Path(r"C:\Users\Charis Computer Hub\Downloads\maramo beauty Institute\assets")
base.mkdir(exist_ok=True)

req = urllib.request.Request(
    'https://r.jina.ai/https://www.tiktok.com/@meramobeautyinstitute',
    headers={'User-Agent': 'Mozilla/5.0'}
)
text = urllib.request.urlopen(req, timeout=45).read().decode('utf-8', errors='ignore')
urls = re.findall(r'https://p\d+-common-sign\.tiktokcdn-us\.com/[^\s\"\')\]>]+', text)

# keep post/profile images, drop suggested-account avatars (cropcenter:100:100)
keep = []
seen = set()
for u in urls:
    u = u.rstrip('.,')
    if 'cropcenter:100:100' in u:
        continue
    if not any(k in u for k in ['photomode', 'origin.image', 'cropcenter:1080:1080', 'tplv-tiktokx-origin.image']):
        continue
    # dedupe by id-ish part
    key = u.split('~')[0]
    if key in seen:
        continue
    seen.add(key)
    keep.append(u)

print('found', len(keep))
headers = {'User-Agent': 'Mozilla/5.0'}
for i, url in enumerate(keep, 1):
    name = f"social-{i}.jpg"
    path = base / name
    if path.exists() and path.stat().st_size > 1000:
        print(name, 'exists')
        continue
    try:
        r = urllib.request.Request(url, headers=headers)
        data = urllib.request.urlopen(r, timeout=30).read()
        path.write_bytes(data)
        print(name, len(data))
    except Exception as e:
        print(name, 'ERR', e)
