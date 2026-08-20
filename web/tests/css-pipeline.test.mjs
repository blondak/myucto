import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const webRoot = fileURLToPath(new URL('../', import.meta.url))
const configFile = fileURLToPath(new URL('../vite.config.ts', import.meta.url))

function transformDevelopmentCss() {
  // Vite loads the native Lightning CSS binding for the configured transformer.
  // Keeping that binding in a node:test worker can race its N-API teardown after
  // server.close(). Isolate the real development transform in a disposable
  // process and hand the result over before exiting; the test process itself
  // never initializes a second native parser.
  //
  // The child writes to a temp FILE, not to stdout. Synchronously writing the
  // whole stylesheet to a pipe throws EAGAIN once it outgrows the pipe buffer —
  // which is exactly what happened on CI after the stylesheet grew past ~130 kB.
  // A file has no such limit and keeps the transfer independent of stylesheet size.
  const outDir = mkdtempSync(join(tmpdir(), 'myucto-css-'))
  const outFile = join(outDir, 'main.css')

  try {
    const script = `
      import { writeFileSync } from 'node:fs'
      import { createServer } from 'vite'

      try {
        const server = await createServer({
          root: ${JSON.stringify(webRoot)},
          configFile: ${JSON.stringify(configFile)},
          logLevel: 'silent',
          server: { middlewareMode: true, hmr: false },
        })
        const transformed = await server.transformRequest('/src/styles/main.css')
        if (transformed === null) throw new Error('Vite did not transform the stylesheet')
        writeFileSync(${JSON.stringify(outFile)}, transformed.code)
        process.exit(0)
      } catch (error) {
        writeFileSync(2, String(error?.stack || error))
        process.exit(1)
      }
    `

    execFileSync(
      process.execPath,
      ['--input-type=module', '--eval', script],
      {
        cwd: webRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'ignore', 'pipe'],
        timeout: 25_000,
      },
    )

    return readFileSync(outFile, 'utf8')
  } finally {
    rmSync(outDir, { recursive: true, force: true })
  }
}

function parseCssBlocks(css) {
  const root = { children: [] }
  const stack = [root]
  let statementStart = 0
  let quote = ''

  for (let i = 0; i < css.length; i += 1) {
    const char = css[i]

    if (quote !== '') {
      if (char === '\\') {
        i += 1
      } else if (char === quote) {
        quote = ''
      }
      continue
    }

    if (char === '"' || char === "'") {
      quote = char
      continue
    }
    if (char === '/' && css[i + 1] === '*') {
      const end = css.indexOf('*/', i + 2)
      i = end === -1 ? css.length : end + 1
      continue
    }
    if (char === '{') {
      const node = {
        header: css.slice(statementStart, i).trim(),
        bodyStart: i + 1,
        bodyEnd: css.length,
        children: [],
      }
      stack.at(-1).children.push(node)
      stack.push(node)
      statementStart = i + 1
      continue
    }
    if (char === '}') {
      assert.ok(stack.length > 1, 'Generated CSS contains an unmatched closing brace')
      stack.pop().bodyEnd = i
      statementStart = i + 1
      continue
    }
    if (char === ';') {
      statementStart = i + 1
    }
  }

  assert.equal(stack.length, 1, 'Generated CSS contains an unclosed block')
  return root.children
}

function isLgBreakpoint(header) {
  const compact = header
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .toLowerCase()
    .replace(/\s+/g, '')
  return (compact.startsWith('@media') || compact.startsWith('@containerworkspace'))
    && (
      compact.includes('(width>=64rem)')
      || compact.includes('(min-width:64rem)')
      || compact.includes('(64rem<=width)')
    )
}

function containsExpandedLgHidden(css, blocks, insideLgBreakpoint = false) {
  for (const block of blocks) {
    const insideLg = insideLgBreakpoint || isLgBreakpoint(block.header)
    const isLgHidden = /\.lg\\:hidden(?![-_a-z0-9])/i.test(block.header)
    const body = css.slice(block.bodyStart, block.bodyEnd)
    if (insideLg && isLgHidden && /\bdisplay\s*:\s*none\s*(?:!important\s*)?;?/i.test(body)) {
      return true
    }
    if (containsExpandedLgHidden(css, block.children, insideLg)) {
      return true
    }
  }
  return false
}

test('development CSS flattens Tailwind workspace-container nesting', { timeout: 30_000 }, () => {
  const transformed = transformDevelopmentCss()
  const encodedCss = transformed.match(
    /const __vite__css = (".*")\r?\n__vite__updateStyle/,
  )?.[1]
  assert.ok(encodedCss, 'Vite CSS module did not contain the injected stylesheet')

  const css = JSON.parse(encodedCss)
  const blocks = parseCssBlocks(css)
  assert.equal(containsExpandedLgHidden(css, blocks), true)
  assert.doesNotMatch(css, /\.lg\\:hidden\s*\{\s*@(media|container)/)
})
