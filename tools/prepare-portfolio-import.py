#!/usr/bin/env python3
"""Build a private, deterministic import package. Never connect to WordPress."""
from pathlib import Path
import hashlib
import json
import shutil
import sys
import zipfile
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'zaplecze/portfolio-output'
DEST = ROOT / 'backend/.local-import'
EXPECTED = {'agroas', 'bekuplast', 'bts', 'drutplast', 'epc', 'funk', 'ledson', 'lobster', 'polakiron', 'rbtsolar', 'remondis'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8-sig'))


def safe_relative(value):
    path = Path(value)
    require(not path.is_absolute() and '..' not in path.parts, f'Unsafe source path: {value}')
    return path


def resolve(raw, archive):
    if not raw:
        return None
    raw = raw.replace('\\', '/')
    if 'portfolio-output/' in raw:
        path = SOURCE / safe_relative(raw.split('portfolio-output/', 1)[1])
        require(path.resolve().is_relative_to(SOURCE.resolve()), 'Source escapes portfolio-output')
        if path.is_file():
            return path.read_bytes(), path.suffix.lower()
    marker = '/Opisy projektów portfolio/'
    if marker in raw:
        name = str(safe_relative(raw.split(marker, 1)[1]))
        if name in archive.namelist():
            return archive.read(name), Path(name).suffix.lower()
    return None


def validate_svg(data):
    require(b'<!doctype' not in data.lower() and b'<!entity' not in data.lower() and b'<?' not in data, 'DTD/entities/processing instructions forbidden in SVG')
    root = ET.fromstring(data)
    require(root.tag == '{http://www.w3.org/2000/svg}svg' and root.get('viewBox'), 'SVG requires namespace and viewBox')
    allowed = {'svg': {'viewBox', 'role'}, 'title': set(), 'path': {'fill', 'd'}}
    for element in root.iter():
        tag = element.tag.removeprefix('{http://www.w3.org/2000/svg}')
        require(tag in allowed and set(element.attrib) <= allowed[tag], f'Unsupported SVG element/attributes: {tag}')
        require(not any('url(' in v.lower() for v in element.attrib.values()), 'External SVG references forbidden')
        if tag == 'path':
            require(element.get('fill') == 'currentColor' and element.get('d'), 'SVG paths must have currentColor fill')


def build():
    audit = read_json(ROOT / 'backend/.local-audit/before-migration/report.json')
    require(not audit['problems'], 'Resolve live audit problems before staging')
    projects = {}
    media = {}
    blobs = {}
    sources = {}

    def remember(path):
        # Keep source keys compatible with existing import packages.
        sources[str(path.relative_to(SOURCE.parent))] = hashlib.sha256(path.read_bytes()).hexdigest()

    def asset(data, extension, mime):
        digest = hashlib.sha256(data).hexdigest()
        name = f'assets/{digest}{extension}'
        blobs[name] = data
        return {'file': name, 'sha256': digest, 'mime': mime, 'bytes': len(data)}

    with zipfile.ZipFile(ROOT / 'zaplecze/projekty-zdjecia.zip') as archive:
        for folder in sorted(SOURCE.iterdir()):
            if not (folder / 'case-study.json').is_file():
                continue
            case_path, manifest_path = folder / 'case-study.json', folder / 'media-manifest.json'
            case, manifest = read_json(case_path), read_json(manifest_path)
            key = case['slug']
            require(key == folder.name and key in EXPECTED and key not in projects, f'Unexpected project key: {key}')
            require(case['schema_version'] == 2 and case['status'] == 'accepted', f'Unapproved schema/status: {key}')
            require(manifest['schema_version'] == 2 and manifest['project_id'] == key, f'Manifest mismatch: {key}')
            url = urlsplit(case['project_url'])
            require(url.scheme in ['http', 'https'] and url.netloc and not any(c.isspace() for c in case['project_url']), f'Invalid project URL: {key}')
            refs = set()
            for image in manifest['images']:
                ident = image['id']
                require(image.get('project_id') == key and ident not in media, f'Duplicate/foreign media: {ident}')
                data = resolve(image.get('asset_path'), archive) or resolve(image.get('original_path'), archive)
                require(data is not None, f'Missing source: {ident}')
                content, extension = data
                if content.startswith(b'\x89PNG\r\n\x1a\n'):
                    extension, mime = '.png', 'image/png'
                elif content.startswith(b'\xff\xd8\xff'):
                    extension, mime = '.jpg', 'image/jpeg'
                elif content[:4] == b'RIFF' and content[8:12] == b'WEBP':
                    extension, mime = '.webp', 'image/webp'
                else:
                    raise ValueError(f'Unsupported raster content: {ident}')
                if image.get('source_code'):
                    require(mime == 'image/png', f'Code screenshot must be PNG: {ident}')
                require(all(isinstance(image.get('alt', {}).get(lang), str) and image['alt'][lang].strip() for lang in ['pl', 'en']), f'Missing localized alt: {ident}')
                media[ident] = {**asset(content, extension, mime), 'project': key, 'alt': image['alt'], 'caption': image.get('caption'), 'provenance': image}
                refs.add(ident)
            require(case['media']['manifest'] == 'media-manifest.json', f'Unexpected manifest reference: {key}')
            require(set(case['media']['gallery']) <= refs, f'Unknown Hero image: {key}')
            hero = sorted((use.get('order', 0), image['id']) for image in manifest['images'] for use in image.get('uses', []) if use.get('section_id') == 'hero' and use.get('role') == 'gallery')
            require([ident for _, ident in hero] == case['media']['gallery'], f'Hero order differs from manifest: {key}')
            challenge_ids = {}
            for lang in ['pl', 'en']:
                local = case[lang]
                for field in ['post_title', 'post_excerpt', 'post_content']:
                    require(isinstance(local[field], str) and local[field].strip(), f'Missing {key}/{lang}/{field}')
                require(local['outcome'] is None or isinstance(local['outcome'], str), f'Invalid outcome: {key}/{lang}')
                require(local['seo']['title'] and local['seo']['meta_description'] and isinstance(local['seo']['keywords'], list), f'Incomplete SEO: {key}/{lang}')
                ids = []
                for challenge in local['challenges']:
                    ident = challenge['id']
                    require(ident not in ids, f'Duplicate Challenge: {key}/{lang}/{ident}')
                    ids.append(ident)
                    materials = challenge['materials']
                    declared = {image['id'] for image in manifest['images'] if any(use.get('section_id') == ident and use.get('role') in ['materials_image', 'material'] for use in image.get('uses', []))}
                    require({shot['media_id'] for shot in materials['images']} == declared, f'Challenge/manifest assignment differs: {key}/{lang}/{ident}')
                    for link in materials['links']:
                        parsed = urlsplit(link['url'])
                        require(parsed.scheme in ['http', 'https'] and parsed.netloc and not any(c.isspace() for c in link['url']), f'Invalid material link: {key}/{lang}/{ident}')
                    require(len(materials['links']) <= 1, f'Multiple links: {key}/{lang}/{ident}')
                    require(all(shot['media_id'] in refs for shot in materials['images']), f'Unknown material: {key}/{lang}/{ident}')
                challenge_ids[lang] = ids
            require(challenge_ids['pl'] == challenge_ids['en'], f'Challenge identity/order differs: {key}')
            projects[key] = case
            remember(case_path)
            remember(manifest_path)
    require(set(projects) == EXPECTED, 'Expected exactly the 11 approved projects')
    tech_path = SOURCE / 'technologies/technology-import.json'
    map_path = SOURCE / 'technologies/project-technology-map.json'
    techs, mapping = read_json(tech_path), read_json(map_path)
    remember(tech_path)
    remember(map_path)
    require(len(techs['create']) == 17 and set(mapping) == EXPECTED, 'Technology import count/project map mismatch')
    icon_assets = {}
    for technology in techs['create']:
        raw = technology['icon'].replace('\\', '/')
        path = SOURCE / safe_relative(raw.split('portfolio-output/', 1)[1])
        require(path.parent == SOURCE / 'technologies/icons', 'Only approved icons may be staged')
        data = path.read_bytes()
        validate_svg(data)
        icon_assets[technology['slug']] = asset(data, '.svg', 'image/svg+xml')
        remember(path)
    aliases = techs['aliases']
    for key, case in projects.items():
        normalized = list(dict.fromkeys(aliases.get(name, name) for name in case['technologies']))
        require(normalized == mapping[key], f'Case/technology map disagreement: {key}')
    package = {
        'schema_version': 1, 'languages': {'pl': 'Polski', 'en': 'English'},
        'projects': projects, 'media': media, 'technologies': techs,
        'project_technologies': mapping, 'icons': icon_assets,
        'existing_project_pairs': audit['existing_project_pairs'],
        'archive_candidates': audit['archive_candidates'], 'source_hashes': sources,
    }
    encoded = json.dumps(package, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()
    stage = DEST.with_name('.local-import-staging')
    require(not stage.exists(), f'Remove or inspect incomplete staging directory: {stage}')
    stage.mkdir(parents=True)
    for name, content in blobs.items():
        target = stage / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    (stage / 'package.json').write_bytes(encoded)
    (stage / 'package.sha256').write_text(hashlib.sha256(encoded).hexdigest() + '\n')
    if DEST.exists():
        require((DEST / 'package.json').read_bytes() == encoded, 'Existing staged package differs; archive it before staging new input')
        require(all((DEST / name).is_file() and (DEST / name).read_bytes() == data for name, data in blobs.items()), 'Existing staged assets differ or are missing')
        shutil.rmtree(stage)
    else:
        stage.rename(DEST)
    print(f'Package ready: {DEST}')
    print(f'{len(projects)} projects / PL+EN; {len(media)} image references; {len(icon_assets)} SVG; {len(blobs)} unique files.')
    print('No WordPress data changed. Package SHA256: ' + hashlib.sha256(encoded).hexdigest())


if __name__ == '__main__':
    try:
        build()
    except (ValueError, KeyError, OSError, ET.ParseError) as error:
        sys.exit('Package rejected: ' + str(error))
