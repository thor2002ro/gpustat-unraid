#!/bin/sh

START="$PWD"

cd "$START"

PKG="igt-gpu-tools"
PKG_DIR=""$PKG"_pkg"
GIT_DIR="$PKG"
OUT_DIR="PKGS"
FLAGS="-O2 -fPIC"

LIBDIRSUFFIX="64"

rm -rf "$PKG_DIR"
rm -rf "$GIT_DIR"

mkdir -p "$OUT_DIR"
mkdir -p "$PKG_DIR"

#GIT
git clone https://gitlab.freedesktop.org/drm/igt-gpu-tools --branch master --depth 1
cd "$GIT_DIR"

mkdir meson-build
cd meson-build

CFLAGS="$FLAGS -Wno-error=array-bounds" \
CXXFLAGS=$FLAGS \
meson setup \
  --prefix=/usr \
  --libdir=/usr/lib${LIBDIRSUFFIX} \
  --sysconfdir=/etc \
  --localstatedir=/var \
  --infodir=/usr/info \
  --mandir=/usr/man \
  -Ddocs=disabled \
  -Dtests=disabled \
  -Drunner=disabled


CFLAGS="$FLAGS" \
CXXFLAGS="$FLAGS" \
ninja
DESTDIR="$START/$PKG_DIR" ninja install

echo -e "\e[95m MAKEPKG "$GIT_DIR""
cd "$START/$PKG_DIR"

#remove man
rm -r "usr/man"

"$START"/makepkg -l n -c y "$START/$OUT_DIR/$GIT_DIR"-$(date +'%Y%m%d')-x86_64-thor.tgz

cd "$START"

rm -rf "$PKG_DIR"
rm -rf "$GIT_DIR"
