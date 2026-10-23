import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
execFileSync(process.execPath, [path.join(root, 'web/node_modules/vite/bin/vite.js'), 'build', '--configLoader', 'native'], {
  cwd: path.join(root, 'web'), stdio: 'inherit',
});
for (const destination of ['server/public/ui', 'agent/ui']) {
  mkdirSync(path.join(root, destination), { recursive: true });
  cpSync(path.join(root, 'web/dist'), path.join(root, destination), { recursive: true });
}
mkdirSync(path.join(root, 'server/resources/docs'), { recursive: true });
cpSync(path.join(root, 'COMPLIANCE.md'), path.join(root, 'server/resources/docs/COMPLIANCE.md'));
console.log('Vue assets copied to Laravel and the Go agent. Rebuild Go to embed changes.');
