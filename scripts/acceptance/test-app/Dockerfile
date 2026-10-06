FROM node:22-alpine
WORKDIR /app
COPY server.js VERSION HEALTH ./
USER node
EXPOSE 3000
CMD ["node", "server.js"]
