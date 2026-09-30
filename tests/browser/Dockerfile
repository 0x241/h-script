FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27
WORKDIR /tests
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY playwright.config.cjs release.spec.cjs csp.config.cjs csp.spec.cjs security-phase4.config.cjs security-phase4.spec.cjs run.sh ./
USER pwuser
CMD ["sh", "run.sh"]
