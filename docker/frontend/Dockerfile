FROM node:20-alpine

RUN apk add --no-cache bash git

WORKDIR /app

COPY frontend/package*.json ./

RUN npm install

COPY frontend/ ./

COPY docker/frontend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 5173

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
