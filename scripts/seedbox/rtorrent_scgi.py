#!/usr/bin/env python3
"""
Helpers SCGI pour rtorrent via socket Unix.
Importe par kick-slow-peers et stop-public-torrents.
"""
import os
import socket
import xml.etree.ElementTree as ET

SCGI_SOCKET = os.environ.get('RTORRENT_SOCK', '/var/run/rtorrent/ropixv2.sock')

def scgi_call(xml_body: str) -> str:
    body = xml_body.encode('utf-8')
    headers = b'CONTENT_LENGTH\x00' + str(len(body)).encode() + b'\x00SCGI\x001\x00'
    netstring = str(len(headers)).encode() + b':' + headers + b','
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sock.settimeout(10)
    sock.connect(SCGI_SOCKET)
    sock.sendall(netstring + body)
    resp = b''
    while True:
        chunk = sock.recv(65536)
        if not chunk:
            break
        resp += chunk
    sock.close()
    if b'\r\n\r\n' in resp:
        resp = resp.split(b'\r\n\r\n', 1)[1]
    return resp.decode('utf-8', errors='replace')


def download_list() -> list:
    xml = '<?xml version="1.0"?><methodCall><methodName>download_list</methodName><params></params></methodCall>'
    resp = scgi_call(xml)
    root = ET.fromstring(resp)
    return [n.text.strip() for n in root.iter('string') if n.text and len(n.text.strip()) == 40]


def multicall(calls: list) -> list:
    """system.multicall -- retourne une liste plate de valeurs."""
    items = ''
    for c in calls:
        params_xml = ''.join(f'<value><string>{p}</string></value>' for p in c['params'])
        items += (
            '<value><struct>'
            f'<member><name>methodName</name><value><string>{c["method"]}</string></value></member>'
            f'<member><name>params</name><value><array><data>{params_xml}</data></array></value></member>'
            '</struct></value>'
        )
    xml = (
        '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
        f'<params><param><value><array><data>{items}</data></array></value></param></params>'
        '</methodCall>'
    )
    resp = scgi_call(xml)
    root = ET.fromstring(resp)
    results = []
    outer = root.find('.//params/param/value/array/data')
    if outer is None:
        return results
    for val in outer:
        inner = val.find('array/data')
        if inner is not None:
            for v in inner:
                # <value><string>x</string></value> ou <value><i8>1</i8></value>
                child = v.find('./')
                if child is not None:
                    results.append(child.text.strip() if child.text else '0')
                else:
                    results.append(v.text.strip() if v.text else '0')
        else:
            child = val.find('./')
            if child is not None:
                results.append(child.text.strip() if child.text else '0')
            else:
                results.append(val.text.strip() if val.text else '0')
    return results


def scgi_simple(method: str, *args) -> str:
    params = ''.join(f'<param><value><string>{a}</string></value></param>' for a in args)
    xml = f'<?xml version="1.0"?><methodCall><methodName>{method}</methodName><params>{params}</params></methodCall>'
    return scgi_call(xml)
