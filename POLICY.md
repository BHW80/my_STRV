# Collaboration & Execution Policy (`POLICY.md`)

Diese Policy definiert die verbindlichen Zusammenarbeits-, Lern- und Ausführungsregeln für das Projekt **YouTube DataOps & Analytics Platform (`my_STRV`)**.

---

## 1. Keine automatischen Ausführungen (No Auto-Execution)
* **Fragepflicht vor jedem Befehl:** Führe niemals unaufgefordert Terminal-Befehle, Python-Skripte, PowerShell-Runner (`*.ps1`), dbt-Läufe oder Terraform-Kommandos aus.
* **Ablauf:** Zeige zuerst den geplanten Befehl (inkl. `.ps1`-Aufruf) und erkläre kurz, was er bewirkt. Warte immer auf die explizite Freigabe des Users, bevor ein Befehl ausgeführt wird.

## 2. Schrittweises Vorgehen & Fokus auf den Lerneffekt (Step-by-Step Learning)
* **Nur die unmittelbar nötigen Schritte implementieren:** Keine vorauseilenden Komplett-Umbauten über mehrere Schichten hinweg.
* **Langsames, nachvollziehbares Tempo:** Wir gehen Schritt für Schritt vor. Jeder Architekturschritt und jede Code-Änderung wird so erklärt, dass der Lern- und Verständniseffekt im Vordergrund steht.

## 3. Transparenz bei Code & PowerShell-Aufrufen
* **Quellcode immer im Chat zeigen:** Bei jeder Änderung oder Neuerstellung von Dateien muss der relevante Quellcode direkt in der Antwort sichtbar dargestellt und erläutert werden.
* **PowerShell-Runner (`.ps1`) immer angeben:** Zeige zu jedem Schritt immer die exakten `.ps1`-Aufrufe (z. B. `.\run_infra.ps1`, `.\run_pubsub_producer.ps1`, `.\run_workflow.ps1`), damit der User sie selbst im Terminal ausführen und nachvollziehen kann.

## 4. Kein automatisches Git Commit & Push
* **Manuelle Git-Freigabe:** Führe niemals automatisch `git add`, `git commit`, `git tag` oder `git push` aus.
* **Ablauf:** Erst wenn der User explizit darum bittet (oder nach Rückfrage zustimmt), werden Änderungen committet und gepusht.
