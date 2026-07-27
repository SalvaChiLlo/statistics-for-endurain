const compareVersions = (a, b) => {
    a = a.replace(/^v/, '');
    b = b.replace(/^v/, '');

    const pa = a.split('.').map(Number);
    const pb = b.split('.').map(Number);

    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const na = pa[i] || 0;
        const nb = pb[i] || 0;
        if (na > nb) return 1;
        if (na < nb) return -1;
    }
    return 0;
}

const fetchCachedLatestRelease = async (repo, cacheKey, cacheTtl) => {
    const now = Date.now();
    const cached = JSON.parse(localStorage.getItem(cacheKey) || 'null');

    if (cached && now - cached.timestamp < cacheTtl) {
        return cached.latestVersion;
    }

    const releaseResponse = await fetch(
        `https://api.github.com/repos/${repo}/releases/latest`,
        { headers: { 'Accept': 'application/vnd.github+json' } }
    );
    if (!releaseResponse.ok) throw new Error('Failed to fetch latest release');

    const latestVersion = (await releaseResponse.json()).name;
    localStorage.setItem(cacheKey, JSON.stringify({ latestVersion, timestamp: now }));

    return latestVersion;
};

const showLatestVersion = (selector, latestVersion) => {
    const $el = document.querySelector(selector);
    if (!$el) return;

    const $link = $el.querySelector('a');
    if ($link) {
        $link.href = $link.href.replace('[LATEST_VERSION]', latestVersion);
        $link.textContent = $link.textContent.replace('[LATEST_VERSION]', latestVersion);
    }
    $el.classList.remove('hidden');
};

const CACHE_TTL = 1000 * 60 * 60 * 6; // 6 hours

// This fork's own repo. Powers the "update available" indicator, which
// compares the latest release of THIS fork against the version currently
// running (AppVersion).
const FORK_REPO = 'SalvaChiLlo/statistics-for-endurain';

// Upstream codebase this fork is based on. Purely informational: it is
// never compared against the running version and never triggers an
// "update available" notice, since divergence from upstream is intentional.
const UPSTREAM_REPO = 'dreeveapp/dreeve';

export const updateGithubLatestRelease = async () => {
    const $latestVersionEl = document.querySelector('[data-latest-version]');
    if (!$latestVersionEl) return;

    const currentVersion = $latestVersionEl.dataset.currentVersion;
    if (!currentVersion) return;

    try {
        const latestVersion = await fetchCachedLatestRelease(FORK_REPO, 'latestReleaseCache', CACHE_TTL);

        if (compareVersions(currentVersion, latestVersion) >= 0) {
            return;
        }

        // Verify Docker image workflow run success before nagging the user,
        // so we don't advertise a release whose image isn't published yet.
        const workflowRunsResponse = await fetch(
            `https://api.github.com/repos/${FORK_REPO}/actions/runs?event=push&status=completed&conclusion=success&exclude_pull_requests=true&branch=${latestVersion}`,
            { headers: { 'Accept': 'application/vnd.github+json' } }
        );
        if (!workflowRunsResponse.ok) throw new Error('Failed to fetch workflow runs');

        const { workflow_runs = [] } = await workflowRunsResponse.json();
        const dockerWorkflowRun = workflow_runs.find(
            run => run.path === '.github/workflows/docker-image.yml'
        );

        if (!dockerWorkflowRun) return;

        showLatestVersion('[data-latest-version]', latestVersion);
    } catch (err) {
        console.error('Error checking latest release:', err);
    }
}

// Purely informational: shows upstream Dreeve's latest release next to this
// fork's own version/update-check UI, without implying this fork is
// "out of date" relative to it.
export const updateUpstreamLatestRelease = async () => {
    const $upstreamVersionEl = document.querySelector('[data-upstream-latest-version]');
    if (!$upstreamVersionEl) return;

    try {
        const latestVersion = await fetchCachedLatestRelease(UPSTREAM_REPO, 'upstreamLatestReleaseCache', CACHE_TTL);
        showLatestVersion('[data-upstream-latest-version]', latestVersion);
    } catch (err) {
        console.error('Error checking upstream latest release:', err);
    }
}
