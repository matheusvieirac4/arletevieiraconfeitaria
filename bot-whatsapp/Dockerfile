# Bot minimalista. Node 20 tem fetch nativo; unica dep e o express.
FROM node:20-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY bot.js ./
# flow.json e a pasta data entram por volume (ver docker-compose.yml)
ENV NODE_ENV=production
CMD ["node", "bot.js"]
