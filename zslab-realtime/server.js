const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.REALTIME_PORT || '3010', 10);
const CORS_ORIGINS = (process.env.CORS_ORIGIN || '')
  .split(',')
  .map(o => o.trim())
  .filter(Boolean);

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: {
    origin: CORS_ORIGINS.length > 0 ? CORS_ORIGINS : false,
    methods: ['GET', 'POST'],
  },
});

app.use(express.json());

app.get('/health', (_req, res) => {
  res.json({ status: 'ok' });
});

// Auto-scan and load handlers
const handlersDir = path.join(__dirname, 'handlers');
function loadHandlers(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      loadHandlers(fullPath);
    } else if (entry.isFile() && entry.name.endsWith('.js')) {
      const handler = require(fullPath);
      if (typeof handler.register === 'function') {
        handler.register(app, io);
        console.log(`[handler] loaded: ${path.relative(handlersDir, fullPath)}`);
      }
    }
  }
}
loadHandlers(handlersDir);

server.listen(PORT, () => {
  console.log(`zslab-realtime listening on port ${PORT}`);
});
