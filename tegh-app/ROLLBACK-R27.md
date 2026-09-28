# R27 rollback instructions

R27 does not change Schema 46, but application/database/private-storage backups must still be treated as one coordinated release set.

1. Stop user writes and record the exact failure time and deployed R27 ZIP SHA-256.
2. Preserve logs and the failed R27 application tree for incident review.
3. Restore the complete exact R26 application backup whose source ZIP SHA-256 is `507e436a9f6b9cc6fe0a78690fec088d1707717987d271c2869b10509134efe3`.
4. If any data/private documents changed after R27 deployment, restore the matching coordinated database/private-storage backup according to the operator's recovery decision. Do not overwrite later valid business data casually.
5. Confirm `tegh-build.json` reports R26 and cache `5990-r26`, purge only the deployment cache covered by the hosting runbook, then run protected startup and accounting-integrity checks before reopening writes.

Never roll back only JavaScript/CSS files, never mix cache revisions, and never run fresh-install SQL over retained company data.
