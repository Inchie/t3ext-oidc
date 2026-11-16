FROM mcr.microsoft.com/playwright:v1.56.1-noble

WORKDIR /e2e

ADD ./e2e/package.json ./e2e/package-lock.json /e2e
RUN npm ci

ADD ./e2e /e2e

CMD ["bash", "-c", "npx playwright test --headed --reporter=line"]
