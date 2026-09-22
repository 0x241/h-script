<?php

// One inventory for both assembly and pre-promotion verification.
return ['composer.report.json', 'npm-production.report.json', 'npm-toolchain.report.json', 'source.report.json', 'shared.report.json',
    'image-amd64.report.json', 'image-arm64.report.json', 'image-amd64.cdx.json', 'image-arm64.cdx.json', 'source.cdx.json', 'shared.cdx.json',
    'image-index.json', 'provenance.json', 'buildkit-sbom.json', 'image-verification.json', 'image-signatures.json',
    'php-version.txt', 'node-npm-version.txt', 'composer-version.txt', 'trivy-version.txt', 'candidate-image.txt',
    'policy.json', 'tools.sh', 'archive-checksums.txt', 'secret-boundary.txt'];
