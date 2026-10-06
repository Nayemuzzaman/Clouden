# Local GitHub simulation for .github/ci/github-flow-test.sh (not github.com).
FROM python:3.12-alpine
RUN apk add --no-cache git git-daemon \
 && git config --system user.name "fake-github" \
 && git config --system user.email "fake-github@privatecloud-e2e.dev"
COPY server.py post_receive.py /app/
ENV GIT_ROOT=/srv/git PORT=8080
EXPOSE 8080
CMD ["python3", "/app/server.py"]
