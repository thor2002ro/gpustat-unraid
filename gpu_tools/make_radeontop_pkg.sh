#!/bin/bash
set -e

PKG="radeontop"
DATE="$(date +'%Y.%m.%d')"
OUT_DIR="PKGS"
IMAGE_NAME="radeontop-builder-temp"

mkdir -p "$OUT_DIR"

echo "Creating temporary Docker image..."

docker build -t "$IMAGE_NAME" - <<'EOF'
FROM ubuntu:latest

ENV DEBIAN_FRONTEND=noninteractive

RUN apt update && apt install -y \
    build-essential \
    git \
    pkg-config \
    libncurses-dev \
    libdrm-dev \
    libpciaccess-dev \
    libudev-dev \
    libxcb1-dev \
    libxcb-dri2-0-dev \
    gettext

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
FLAGS='-O2 -fPIC'
LIBDIRSUFFIX=64

rm -rf \"\$PKG_DIR\" ${PKG}
mkdir -p \"\$PKG_DIR\"

git clone https://github.com/clbr/radeontop.git --branch master --depth 1
cd ${PKG}

CFLAGS=\"\$FLAGS\" make amdgpu=1 xcbgpu=0 -j\$(nproc)

make install nls=0 xcbgpu=0 \
  PREFIX=/usr \
  LIBDIR=lib\${LIBDIRSUFFIX} \
  MANDIR=man \
  DESTDIR=\"\$START/\$PKG_DIR\"

cd \"\$START/\$PKG_DIR\"

#remove man
rm -rf usr/man

\"\$START\"/makepkg -l n -c y \"\$START/${OUT_DIR}/${PKG}-${DATE}-x86_64-thor.tgz\"

cd \"\$START\"
rm -rf \"\$PKG_DIR\" ${PKG}
"

echo "Cleaning up Docker image..."
docker rmi "$IMAGE_NAME" >/dev/null

echo "Done. Package available in ${OUT_DIR}/"
