const { execFile } = require('child_process');

const INTERNAL_KEY = process.env.INTERNAL_KEY || '';

const BLOCKED_FIELDS = ['ip', 'networks', 'env', 'environment', 'mounts'];
const SENSITIVE_NAME_PATTERN = /secret|password|key|token/i;

function sanitizeContainer(raw) {
  const out = {};
  for (const [k, v] of Object.entries(raw)) {
    if (BLOCKED_FIELDS.includes(k.toLowerCase())) continue;
    if (k === 'Names' || k === 'Image') {
      out[k] = SENSITIVE_NAME_PATTERN.test(String(v)) ? '***' : v;
    } else {
      out[k] = v;
    }
  }
  return out;
}

function fetchContainerList(callback) {
  // Only this exact command — no external input used as args
  execFile('docker', ['ps', '--format', '{{json .}}'], { timeout: 10000 }, (err, stdout) => {
    if (err) { callback(err, null); return; }
    const containers = stdout
      .trim()
      .split('\n')
      .filter(Boolean)
      .map(line => { try { return sanitizeContainer(JSON.parse(line)); } catch { return null; } })
      .filter(Boolean);
    callback(null, containers);
  });
}

function fetchNginxStats(callback) {
  // Only this exact hardcoded command — no external input used as args
  execFile(
    'docker',
    ['exec', 'gateway_nginx', 'tail', '-n', '5000', '/var/log/nginx/access.log'],
    { timeout: 10000, maxBuffer: 10 * 1024 * 1024 },
    (err, stdout) => {
      if (err) {
        callback(null, { requests_last_minute: 0, avg_response_ms: 0 });
        return;
      }

      const cutoff = Date.now() - 60 * 1000;
      let count = 0;
      let avgCount = 0;
      let totalMs = 0;

      for (const line of stdout.trim().split('\n')) {
        if (!line) continue;
        let parsed;
        try { parsed = JSON.parse(line); } catch { continue; }

        const ts = new Date(parsed.time).getTime();
        if (isNaN(ts) || ts < cutoff) continue;

        const rt = parseFloat(parsed.request_time);
        if (isNaN(rt)) continue;

        count++;
        // Exclude requests > 5s from avg (timeouts / proxied uploads skew the mean)
        if (rt <= 5) {
          totalMs += rt * 1000;
          avgCount++;
        }
      }

      // Return only aggregated numbers — no raw lines, URLs, or IPs
      callback(null, {
        requests_last_minute: count,
        avg_response_ms: avgCount > 0 ? Math.round(totalMs / avgCount) : 0,
      });
    }
  );
}

function collectAll(callback) {
  let containers = null;
  let nginx = null;
  let done = 0;
  let errored = false;

  function finish(err) {
    if (errored) return;
    if (err) { errored = true; callback(err, null); return; }
    if (++done === 2) callback(null, { containers, nginx });
  }

  fetchContainerList((err, result) => {
    if (err) { finish(err); return; }
    containers = result;
    finish(null);
  });

  fetchNginxStats((_err, result) => {
    // nginx stats failure is non-fatal — fall back to zeros
    nginx = result || { requests_last_minute: 0, avg_response_ms: 0 };
    finish(null);
  });
}

function register(app, io) {
  const portfolioNs = io.of('/portfolio');

  portfolioNs.on('connection', socket => {
    collectAll((err, data) => {
      if (!err) socket.emit('infra:update', data);
    });
  });

  // Internal-only trigger endpoint
  app.post('/internal/portfolio/infra-trigger', (req, res) => {
    const clientKey = req.headers['x-internal-key'] || '';
    if (!INTERNAL_KEY || clientKey !== INTERNAL_KEY) {
      return res.status(403).json({ error: 'forbidden' });
    }

    collectAll((err, data) => {
      if (err) {
        console.error('[infra-trigger] error:', err.message);
        return res.status(500).json({ error: 'data collection failed' });
      }
      portfolioNs.emit('infra:update', data);
      res.json({ ok: true, count: data.containers.length, nginx: data.nginx });
    });
  });
}

module.exports = { register };
