/**
 * Crawls both locales of a running site into a validator snapshot.
 *
 * Discovery starts at the sitemap index and follows it into every locale
 * sitemap and every listed address, then adds the surfaces that must never be
 * listed - search, filtered search, drafts, and legacy addresses - so the
 * validators can prove they stay out of the index.
 */

const MACHINE_PATHS = [
  "/robots.txt",
  "/sitemap.xml",
  "/sitemap-pt-br.xml",
  "/sitemap-en.xml",
  "/pt-br/noticias/feed/",
  "/en/news/feed/",
  "/pt-br/eventos/feed/",
  "/en/events/feed/",
];

export async function crawl(baseUrl, options = {}) {
  const origin = baseUrl.replace(/\/+$/, "");
  const extraPaths = options.extraPaths ?? [];
  const maxDocuments = options.maxDocuments ?? 400;
  const concurrency = options.concurrency ?? 3;
  const timeoutMs = options.timeoutMs ?? 120000;
  const cookie = await warmUp(origin, timeoutMs);
  const documents = new Map();

  const fetchAll = async (paths) => {
    const pending = paths.filter((path) => !documents.has(path));
    for (let index = 0; index < pending.length; index += concurrency) {
      const batch = pending.slice(index, index + concurrency);
      const fetched = await Promise.all(
        batch.map((path) => fetchDocument(origin, path, cookie, timeoutMs)),
      );
      for (const document of fetched) {
        if (documents.size < maxDocuments) {
          documents.set(document.path, document);
        }
      }
    }
  };

  await fetchAll(MACHINE_PATHS);

  const listed = [];
  for (const path of ["/sitemap.xml", "/sitemap-pt-br.xml", "/sitemap-en.xml"]) {
    const sitemap = documents.get(path);
    if (sitemap !== undefined) {
      listed.push(...locations(sitemap.body, origin));
    }
  }
  await fetchAll([...new Set(listed)].filter((path) => !path.endsWith(".xml")));
  await fetchAll(extraPaths);

  return [...documents.values()].sort((left, right) => left.path.localeCompare(right.path));
}

async function warmUp(origin, timeoutMs) {
  const response = await fetch(`${origin}/pt-br/`, {
    redirect: "manual",
    signal: AbortSignal.timeout(timeoutMs),
  });
  const raw = response.headers.getSetCookie?.() ?? [];
  await response.arrayBuffer();
  return raw.map((value) => value.split(";")[0]).join("; ");
}

const MAX_ATTEMPTS = 5;

/**
 * Fetches one document, retrying transient server and transport failures.
 *
 * A development server under load answers `5xx` or drops the socket outright.
 * Both are retried with a widening pause so a single flaky response cannot
 * discard an entire crawl, while a genuine `4xx` is recorded as-is because the
 * validators need to prove which addresses refuse to serve.
 */
async function fetchDocument(origin, path, cookie, timeoutMs, attempt = 0) {
  let response;
  try {
    response = await fetch(`${origin}${path}`, {
      redirect: "manual",
      headers: cookie === "" ? {} : { cookie },
      signal: AbortSignal.timeout(timeoutMs),
    });
  } catch (error) {
    if (attempt + 1 >= MAX_ATTEMPTS) {
      throw error;
    }
    await pause(attempt);
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  const body = await response.text();
  if (response.status >= 500 && attempt + 1 < MAX_ATTEMPTS) {
    await pause(attempt);
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  return {
    path,
    status: response.status,
    contentType: (response.headers.get("content-type") ?? "").split(";")[0].trim(),
    location: response.headers.get("location") ?? "",
    body,
  };
}

function locations(xml, origin) {
  const found = [];
  for (const match of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    const url = match[1].trim();
    if (url.startsWith(origin)) {
      found.push(url.slice(origin.length) || "/");
    }
  }
  return found;
}

/**
 * Waits a widening interval between crawl retries.
 *
 * @param {number} attempt Zero-based attempt already spent.
 */
function pause(attempt) {
  return new Promise((resolve) => setTimeout(resolve, 1000 * 2 ** attempt));
}
