#!/bin/bash
set -e

PKG="igt-gpu-tools"
DATE="$(date +'%Y.%m.%d')"
OUT_DIR="PKGS"
IMAGE_NAME="igt-builder-temp"

mkdir -p "$OUT_DIR"

echo "Creating temporary Docker image..."

docker build -t "$IMAGE_NAME" - <<'EOF'
FROM ubuntu:latest

ENV DEBIAN_FRONTEND=noninteractive

RUN apt update && apt install -y \
    build-essential \
    git \
    pkg-config \
    meson \
    ninja-build \
    python3 \
    flex \
    bison \
    libncurses-dev \
    libudev-dev \
    libkmod-dev \
    libpciaccess-dev \
    libdrm-dev \
    libproc2-dev \
    libunwind-dev \
    libdw-dev \
    libssl-dev \
    libpixman-1-dev \
    libglib2.0-dev \
    libcairo2-dev \
    libpci-dev \
    valgrind

WORKDIR /builder
EOF

echo "Running build inside container..."

docker run --rm \
    -v "$PWD":/builder \
    -w /builder \
    "$IMAGE_NAME" \
    bash -c "
set -e

START=\$PWD
PKG_DIR=${PKG}_pkg
GIT_DIR=${PKG}
FLAGS='-O2 -fPIC'
LIBDIRSUFFIX=64

rm -rf \"\$PKG_DIR\" \"\$GIT_DIR\"
mkdir -p \"\$PKG_DIR\"

git clone https://gitlab.freedesktop.org/drm/igt-gpu-tools.git --branch master --depth 1
cd \"\$GIT_DIR\"

mkdir meson-build
cd meson-build

CFLAGS=\"\$FLAGS -Wno-error=array-bounds\" CXXFLAGS=\"\$FLAGS\" meson setup \\
  --prefix=/usr \\
  --libdir=/usr/lib\${LIBDIRSUFFIX} \\
  --sysconfdir=/etc \\
  --localstatedir=/var \\
  --infodir=/usr/info \\
  --mandir=/usr/man \\
  -Ddocs=disabled \\
  -Dtests=disabled \\
  -Drunner=disabled

CFLAGS=\"\$FLAGS\" CXXFLAGS=\"\$FLAGS\" ninja

DESTDIR=\"\$START/\$PKG_DIR\" ninja install

cd \"\$START/\$PKG_DIR\"

#remove man
rm -rf usr/man

\"\$START\"/makepkg -l n -c y \"\$START/${OUT_DIR}/${PKG}-${DATE}-x86_64-thor.tgz\"

cd \"\$START\"
rm -rf \"\$PKG_DIR\" \"\$GIT_DIR\"
"

echo "Cleaning up Docker image..."
docker rmi "$IMAGE_NAME" >/dev/null

echo "Done. Package available in ${OUT_DIR}/"
