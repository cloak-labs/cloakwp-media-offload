import { createServer } from 'node:http';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';

const objects = new Map();
const server = createServer(async (req, res) => {
  const key = req.url;
  if (req.method === 'PUT') {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = Buffer.concat(chunks);
    if (req.headers['content-md5'] !== createHash('md5').update(body).digest('base64') || req.headers['if-none-match'] !== '*') {
      res.writeHead(400); res.end('Bad checksum or conditional write'); return;
    }
    if (objects.has(key)) { res.writeHead(412, { 'content-type': 'application/xml' }); res.end('<Error><Code>PreconditionFailed</Code></Error>'); return; }
    objects.set(key, { body, sha: req.headers['x-amz-meta-sha256'] });
    res.writeHead(200, { etag: `"${createHash('md5').update(body).digest('hex')}"` }); res.end(); return;
  }
  const object = objects.get(key);
  if (!object) { res.writeHead(404, { 'content-type': 'application/xml' }); res.end('<Error><Code>NoSuchKey</Code></Error>'); return; }
  res.writeHead(200, { 'content-length': object.body.length, 'x-amz-meta-sha256': object.sha });
  res.end(req.method === 'HEAD' ? undefined : object.body);
});
server.listen(0, '127.0.0.1', () => {
  const endpoint = `http://127.0.0.1:${server.address().port}`;
  const php = spawn('php', ['tests/s3-http.php'], { env: { ...process.env, MEDIA_OFFLOAD_TEST_ENDPOINT: endpoint }, stdio: 'inherit' });
  php.on('error', (error) => { console.error(error); server.close(); process.exitCode = 1; });
  php.on('exit', (code) => { server.close(); process.exitCode = code ?? 1; });
});
