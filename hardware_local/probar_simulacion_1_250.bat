@echo off
PowerShell -NoProfile -Command "Invoke-RestMethod -Headers @{'X-POS-Hardware-Token'='pescadores-hardware-local'} -Uri 'http://127.0.0.1:8787/bascula/simular?peso=1.250' -Method POST | ConvertTo-Json"
pause
