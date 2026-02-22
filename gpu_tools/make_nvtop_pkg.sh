#!/bin/bash
set -e

PKG="nvtop"
DATE="$(date +'%Y.%m.%d')"
OUT_DIR="PKGS"
IMAGE_NAME="nvtop-builder-temp"

mkdir -p "$OUT_DIR"

echo "Creating temporary Docker image..."

docker build -t "$IMAGE_NAME" - <<'EOF'
FROM ubuntu:latest

ENV DEBIAN_FRONTEND=noninteractive

RUN apt update && apt install -y \
    build-essential \
    git \
    cmake \
    pkg-config \
    libncurses-dev \
    libdrm-dev \
    libpciaccess-dev \
    libudev-dev \
    wget

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

rm -rf \"\$PKG_DIR\" ${PKG}
mkdir -p \"\$PKG_DIR\"

git clone https://github.com/thor2002ro/nvtop.git --depth 1
cd ${PKG}
mkdir build && cd build

cmake .. \
  -DNVIDIA_SUPPORT=ON \
  -DAMDGPU_SUPPORT=ON \
  -DINTEL_SUPPORT=ON \
  -DCMAKE_C_FLAGS=\"\$FLAGS\" 

make -j\$(nproc)

make install \
  PREFIX=/usr \
  LIBDIR=lib64 \
  DESTDIR=\"\$START/\$PKG_DIR\"

cd \"\$START/\$PKG_DIR\"

\"\$START\"/makepkg -l n -c y \"\$START/${OUT_DIR}/${PKG}-${DATE}-x86_64-thor.tgz\"

cd \"\$START\"
rm -rf \"\$PKG_DIR\" ${PKG}
"

echo "Cleaning up Docker image..."
docker rmi "$IMAGE_NAME" >/dev/null

echo "Done. Package available in ${OUT_DIR}/"
