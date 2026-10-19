import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
execFileSync(process.platform === 'win32' ? 'npm.cmd' : 'npm', ['run', 'build'], {
  cwd: path.join(root, 'web'), stdio: 'inherit', shell: process.platform === 'win32',
});
for (const destination of ['server/public/ui', 'agent/ui']) {
  mkdirSync(path.join(root, destination), { recursive: true });
  cpSync(path.join(root, 'web/dist'), path.join(root, destination), { recursive: true });
}
console.log('Vue assets copied to Laravel and the Go agent. Rebuild Go to embed changes.');
