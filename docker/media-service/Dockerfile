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

RUN npm install

COPY media-service/ ./

RUN mkdir -p /app/storage /app/tmp

COPY docker/media-service/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 3000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["npm", "run", "dev"]
