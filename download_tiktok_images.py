import pathlib
import urllib.request

base = pathlib.Path(r"C:\Users\Charis Computer Hub\Downloads\maramo beauty Institute\assets")
base.mkdir(exist_ok=True)

imgs = {
    "tiktok-avatar.jpg": "https://p16-common-sign.tiktokcdn-us.com/tos-alisg-avt-0068/ded844d59c6eb58400035a7a9064469f~tplv-tiktokx-cropcenter:1080:1080.jpeg?dr=9640&refresh_token=17420b22&x-expires=1795608000&x-signature=qIBbV9MvHXdGcH4%2B%2F7FXave3Ty8%3D&t=4d5b0474&ps=13740610&shp=a5d48078&shcp=81f88b70&idc=useast5",
    "tiktok-1.jpg": "https://p16-common-sign.tiktokcdn-us.com/tos-alisg-i-photomode-sg/0bbb7d1197d64f3ababf17cf9e33c84a~tplv-photomode-image.jpeg?dr=9616&x-expires=1795608000&x-signature=9Rk8B3jeVKsKXgyehX5CsF7OhEE%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=9b759fb9&idc=useast5&ftpl=1",
    "tiktok-2.jpg": "https://p19-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/owirMrAAfACiA5DFEgeAkuQgGFRrBAjADUEIBg~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=%2BLjudap9slKKLdBor%2Bqz5K36IZg%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
    "tiktok-3.jpg": "https://p19-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/o8NBiCdGqEyAlDz9EAAaifNRUWfpqIANIBSRgj~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=PEtOZA4OvgrM3Oqwnbucrjh0HtU%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
    "tiktok-4.jpg": "https://p16-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/oQUUoaiEtaY6EDAHVAhYBgnAAAu2IWMoiBnOv~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=87yYvkyn3X5A8QX9FSiWnH8g%2Brk%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
    "tiktok-5.jpg": "https://p16-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/ocErIABARCD6A0BqqiDZfpW3Oh8EeiAgUbwHCV~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=K2Y3Hl2Wh3uk%2FwLWs99YqNrI0RA%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
    "tiktok-6.jpg": "https://p16-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/oQEArIY8PKBU6rqVX8EZBIa7lcO3nAKiI5isP~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=dTmDl6VTnGPPXC8XMx5FY1ub6DY%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
    "tiktok-7.jpg": "https://p19-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/oMvBAkqQO8wDiCRkgvcl0fEOFEARGAxvfAjiAI~tplv-tiktokx-origin.image?dr=9636&x-expires=1795608000&x-signature=2lEAtHCL1lLxgt9E%2BfMRbOKBkAA%3D&t=4d5b0474&ps=13740610&shp=81f88b70&shcp=43f4a2f9&idc=useast5",
}

headers = {"User-Agent": "Mozilla/5.0"}
for name, url in imgs.items():
    try:
        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req, timeout=30) as r:
            data = r.read()
        (base / name).write_bytes(data)
        print(name, len(data))
    except Exception as e:
        print(name, "ERR", e)
