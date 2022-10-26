#!/bin/sh

START="$PWD"

cd "$START"

PKG="radeontop"
PKG_DIR=""$PKG"_pkg"
GIT_DIR="$PKG"
FLAGS="-O2 -fPIC"

LIBDIRSUFFIX="64"

rm -rf "$PKG_DIR"
rm -rf "$GIT_DIR"

mkdir -p "$PKG_DIR"

#GIT
git clone https://github.com/clbr/radeontop.git --branch master --depth 1
cd "$GIT_DIR"

CFLAGS="$FLAGS" \
make amdgpu=1

make install nls=0 \
  PREFIX=/usr \
  LIBDIR=lib${LIBDIRSUFFIX} \
  MANDIR=man \
  DESTDIR="$START/$PKG_DIR"

echo -e "\e[95m MAKEPKG "$GIT_DIR""
cd "$START/$PKG_DIR"

#remove man
rm -r "usr/man"

fakeroot "$START"/../makepkg -l n -c y "$START/../pkg/$PKG"-$(date +"%Y.%m.%d")-x86_64-thor.tgz

cd "$START"

rm -rf "$PKG_DIR"
rm -rf "$GIT_DIR"
