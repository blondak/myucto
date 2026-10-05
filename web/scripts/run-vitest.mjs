import { spawnSync } from 'node:child_process'
import { realpathSync } from 'node:fs'
import { join } from 'node:path'

const cwd = realpathSync(process.cwd()).replace(
  /^[a-z]:/u,
  drive => drive.toUpperCase(),
)
const vitest = join(cwd, 'node_modules', 'vitest', 'vitest.mjs')
const args = process.argv.slice(2).filter(arg => arg !== '--')
for (let index = 0; index < args.length; index++) {
  const argument = args[index]
  if (!argument) continue
  const [option, value] = argument.split('=', 2)
  const concurrency = option === '--maxConcurrency' || option === '--max-concurrency'
  if (!concurrency && option !== '--maxWorkers' && option !== '--max-workers') continue
  const workers = Number(value ?? args[++index])
  const maximum = concurrency ? 1 : 8
  if (!Number.isInteger(workers) || workers < 1 || workers > maximum) {
    console.error(`${option} musí být celé číslo od 1 do ${maximum}.`)
    process.exit(2)
  }
}
const result = spawnSync(process.execPath, [vitest, ...args], {
  cwd,
  env: {
    ...process.env,
    INIT_CWD: cwd,
    PWD: cwd,
  },
  stdio: 'inherit',
})

if (result.error) {
  throw result.error
}
process.exit(result.status ?? 1)
