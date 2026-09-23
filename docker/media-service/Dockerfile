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

RUN npm ci

COPY media-service/ ./

RUN mkdir -p /app/storage /app/tmp

EXPOSE 3000

CMD ["npm", "run", "dev"]
