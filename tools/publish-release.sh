#!/usr/bin/env bash
set -euo pipefail
: "${GITHUB_REPOSITORY:?Repository identity is required}"
: "${GITHUB_SHA:?Reviewed source SHA is required}"
: "${GH_TOKEN:?An authorized release token is required}"
[[ $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]] || exit 2
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" ]] || { echo 'Wrong checked-out revision.' >&2; exit 1; }
[[ $(gh api "repos/$GITHUB_REPOSITORY/commits/main" --jq .sha) == "$GITHUB_SHA" ]] || { echo 'Superseded main revision; no release published.'; exit 0; }
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
# Invoked only by the main-branch job after Quality, Docker integration and package tests.
php tools/release.php plan > "$work/plan.json"
tag=$(jq -er .tag "$work/plan.json")
version=$(jq -er .version "$work/plan.json")
php tools/release.php version "$version" >/dev/null
bash tools/package.sh "$version" "$work/package"
if gh release view "$tag" --repo "$GITHUB_REPOSITORY" --json isDraft,targetCommitish > "$work/existing.json" 2>/dev/null; then
    if [[ $(jq -r .isDraft "$work/existing.json") != true ]]; then
        gh release download "$tag" --repo "$GITHUB_REPOSITORY" --pattern release.json --dir "$work/existing"
        [[ $(jq -r .commit "$work/existing/release.json") == "$GITHUB_SHA" ]] || { echo 'Published tag belongs to different source.' >&2; exit 1; }
        echo 'This revision is already published; immutable assets were not changed.'
        exit 0
    fi
    [[ $(jq -r .targetCommitish "$work/existing.json") == "$GITHUB_SHA" ]] || { echo 'Draft target conflict.' >&2; exit 1; }
else
    # Refuse a pre-existing tag pointing to other code, even if no release exists.
    if git rev-parse --verify "$tag^{commit}" > "$work/tag-sha" 2>/dev/null; then
        [[ $(cat "$work/tag-sha") == "$GITHUB_SHA" ]] || { echo 'Tag conflict.' >&2; exit 1; }
    fi
    gh release create "$tag" --repo "$GITHUB_REPOSITORY" --target "$GITHUB_SHA" --draft --title "$tag" --generate-notes
fi
gh release upload "$tag" --repo "$GITHUB_REPOSITORY" --clobber "$work/package/"*
gh release download "$tag" --repo "$GITHUB_REPOSITORY" --dir "$work/downloaded"
(cd "$work/downloaded" && sha256sum -c SHA256SUMS)
[[ $(gh api "repos/$GITHUB_REPOSITORY/commits/main" --jq .sha) == "$GITHUB_SHA" ]] || { echo 'Main advanced during packaging; leaving the draft unpublished.'; exit 0; }
# All artifacts exist and match before latest changes. No published release is overwritten.
gh release edit "$tag" --repo "$GITHUB_REPOSITORY" --draft=false --latest
