# CI/CD Setup Guide

This document explains how to set up the GitHub Actions CI/CD pipeline for automated deployments.

## What's Included

- **GitHub Actions Workflow** (`.github/workflows/deploy.yml`)
  - Triggers on every push to `main` branch
  - Builds Docker images for API and Web
  - Pushes images to GitHub Container Registry (GHCR)
  - Deploys to production server
  - Runs health checks
  - Sends Slack notifications

- **Deployment Script** (`scripts/deploy.sh`)
  - Pulls latest code from GitHub
  - Logs into GHCR
  - Pulls latest Docker images
  - Recreates containers
  - Verifies container status

- **Health Check Script** (`scripts/health-check.sh`)
  - Checks Web UI is responding
  - Checks API is responding
  - Retries with exponential backoff
  - Reports status

## Setup Instructions

### 1. Generate SSH Deploy Key (One-time)

Create a dedicated SSH key pair for GitHub Actions to use for server deployment:

```bash
# On your local machine, generate a new SSH key
ssh-keygen -t rsa -b 4096 -f ~/.ssh/deploy_key_pdo -C "github-actions-deploy"
# Don't set a passphrase (leave it empty for automated deploys)

# Add public key to server's authorized_keys
ssh-copy-id -i ~/.ssh/deploy_key_pdo.pub ubuntu@54.206.23.51

# Or manually add it:
cat ~/.ssh/deploy_key_pdo.pub | ssh ubuntu@54.206.23.51 'cat >> ~/.ssh/authorized_keys'
```

### 2. Add GitHub Secrets

Go to your GitHub repository → **Settings** → **Secrets and variables** → **Actions** and add:

#### Required Secrets:

1. **`DEPLOY_SSH_KEY`**
   - Value: Contents of `~/.ssh/deploy_key_pdo` (the private key)
   - Copy entire content including `-----BEGIN RSA PRIVATE KEY-----` and `-----END RSA PRIVATE KEY-----`

2. **`SLACK_WEBHOOK_URL`** (optional, but recommended)
   - Go to Slack Workspace → Apps → Create an Incoming Webhook
   - Slack Setup:
     1. Go to api.slack.com/apps
     2. Create New App → From scratch
     3. Name: "Barumun PDO Deployments"
     4. Workspace: Your workspace
     5. Go to Incoming Webhooks → Activate
     6. Add New Webhook to Channel → Select deployment channel
     7. Copy the Webhook URL
   - Add to GitHub: Paste the Webhook URL as `SLACK_WEBHOOK_URL`

#### GitHub Token (Already Available)
- `GITHUB_TOKEN` is automatically provided by GitHub Actions (no setup needed)
- It's used to push images to GHCR and authenticate the workflow

### 3. Server Setup

Ensure the server has Docker installed and can pull from GHCR:

```bash
# SSH into server
ssh -i ~/Downloads/BPN.pem ubuntu@54.206.23.51

# The deploy script handles login automatically, but verify docker is running:
docker ps

# Verify you can manually pull from GHCR (this will fail until images are pushed)
# Don't worry if this fails on first run - CI/CD will push the images
```

## How It Works

### Workflow on Every `main` Push:

1. **Build Phase** (GitHub Actions Runner)
   - Checks out code
   - Sets up Docker Buildx
   - Logs into GitHub Container Registry
   - Builds API Docker image
   - Builds Web Docker image
   - Pushes both images to GHCR with tags:
     - `main` (latest from main branch)
     - `main-<short-sha>` (specific commit)

2. **Deploy Phase** (On Server via SSH)
   - Fetches latest code from GitHub
   - Logs into GHCR using `GITHUB_TOKEN`
   - Pulls latest images: `ghcr.io/gunandasrg-halotec/barumun-pdo/api:main`
   - Pulls latest images: `ghcr.io/gunandasrg-halotec/barumun-pdo/web:main`
   - Tags images for docker-compose
   - Stops old containers
   - Starts new containers with fresh images
   - Verifies containers are running

3. **Health Check Phase** (GitHub Actions)
   - Waits 5 seconds for services to stabilize
   - Checks Web UI (https://pdo.barumun-plantation.com)
   - Checks API responsiveness
   - Retries up to 5 times if services aren't ready

4. **Notification Phase**
   - On Success: Sends Slack message with deployment details and links
   - On Failure: Sends Slack alert with workflow link for debugging

## Testing the Pipeline

### Option 1: Make a Test Commit

```bash
cd ~/Documents/CodingWork/Barumun-pdo/barumun-pdo
echo "# Test deployment" >> README.md
git add README.md
git commit -m "test: trigger CI/CD pipeline"
git push origin main
```

Then watch the workflow:
1. Go to GitHub repository → **Actions**
2. Click the latest workflow run
3. Watch build-and-push and deploy jobs execute
4. Check Slack for notification (if configured)

### Option 2: Manual Trigger (Not in Current Setup)

If you want to add manual trigger capability, add this to the top of `.github/workflows/deploy.yml`:

```yaml
on:
  push:
    branches:
      - main
  workflow_dispatch:  # Allows manual trigger from GitHub UI
```

## Monitoring & Troubleshooting

### View Workflow Logs

1. Go to **Actions** tab on GitHub
2. Click the workflow run
3. Click the job (build-and-push or deploy)
4. View logs in real-time or after completion

### Common Issues

#### 1. SSH Key Permission Denied
- Check `DEPLOY_SSH_KEY` secret is correctly set (entire private key content)
- Verify public key is in `~/.ssh/authorized_keys` on server
- Test manually: `ssh -i deploy_key ubuntu@54.206.23.51`

#### 2. GHCR Image Not Found
- Ensure first build succeeded in Actions tab
- Check image name matches workflow: `ghcr.io/gunandasrg-halotec/barumun-pdo/api:main`
- Verify repository is public (or runner has access to private registry)

#### 3. Health Check Fails After Deployment
- Check container logs on server: `docker compose -f docker-compose.prod.yml logs api`
- Verify containers started: `docker ps`
- Check network connectivity: `curl -I https://pdo.barumun-plantation.com`

#### 4. Slack Webhook Not Working
- Verify `SLACK_WEBHOOK_URL` is correct in GitHub Secrets
- Test webhook manually: `curl -X POST -H 'Content-type: application/json' --data '{"text":"test"}' YOUR_WEBHOOK_URL`
- Check Slack workspace webhook is still active (they can expire)

## Disabling/Pausing Deployments

To temporarily pause CI/CD without removing the workflow:

1. Go to Actions tab
2. Click "Disable workflow" on the workflow
3. To re-enable, click "Enable workflow"

Or delete `.github/workflows/deploy.yml` to disable permanently (remove and commit).

## Next Steps

1. ✅ Files created locally (workflow, scripts, guide)
2. ⏭️ **Push these files to GitHub**
3. ⏭️ **Add GitHub Secrets** (DEPLOY_SSH_KEY, SLACK_WEBHOOK_URL)
4. ⏭️ **Set up SSH key pair** (generate and add to server)
5. ⏭️ **Test the pipeline** (make a test commit)
6. ⏭️ **Verify Slack notifications** (if using Slack)

## File Structure

```
.github/
  workflows/
    deploy.yml              # Main CI/CD workflow
scripts/
  deploy.sh               # Server deployment script
  health-check.sh         # Health check script
CI_CD_SETUP.md           # This file
```

## Security Notes

- **SSH Key**: Never commit the private SSH key to the repository
- **GitHub Token**: Only valid during workflow execution, automatically scoped to current repo
- **Slack Webhook**: Can be rotated at any time via Slack workspace settings
- **Least Privilege**: The deploy SSH key only needs access to pull code and manage Docker

---

For questions or issues, check the GitHub Actions logs first, then verify server state manually with SSH.
