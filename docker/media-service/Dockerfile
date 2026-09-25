FROM node:20-alpine

# FFmpeg + ferramentas de mídia
RUN apk add --no-cache \
    bash \
    git \
    ffmpeg \
    python3 \
    make \
    g++

WORKDIR /app

COPY media-service/package*.json ./

# Os patches precisam existir antes do install: o `postinstall` roda o
# patch-package, que só encontra `patches/` se a pasta já estiver no contexto.
# Copiada depois, o patch do webtorrent era silenciosamente ignorado
# ("No patch files found") e a imagem saía com o bug que ele corrige.
COPY media-service/patches ./patches

RUN npm install

COPY media-service/ ./

RUN mkdir -p /app/storage /app/tmp

COPY docker/media-service/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 3000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["npm", "run", "dev"]
