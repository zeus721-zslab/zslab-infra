FROM node:20-alpine

WORKDIR /app

# Install docker CLI + create docker group matching host GID (982)
RUN apk add --no-cache docker-cli \
  && addgroup -g 982 docker \
  && adduser node docker

COPY package.json .
RUN npm install --production

COPY . .

USER node

CMD ["node", "server.js"]
