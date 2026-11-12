---
trigger: always_on
description: Verbindliche Arbeits-, Lern- und Ausführungsregeln für my_STRV (Keine Auto-Executes, keine Auto-Commits, Schritt-für-Schritt-Lernen, Code & .ps1 zeigen, Cloud-Destroy-Hinweis, /docs nur auf Anfrage).
---

# Verbindliche Projekt-Policy (my_STRV)

Halte dich in jedem Schritt strikt an folgende Regeln:

1. **Keine automatischen Executes:** Führe niemals ungefragt Befehle (`run_command`), Skripte, Terraform- oder Pipeline-Läufe aus. Frage immer zuerst um Erlaubnis.
2. **Schrittweise Implementierung (Lern-Modus):** Implementiere immer nur genau den angefragten bzw. unmittelbar nötigen Schritt. Gehe langsam vor und erkläre das *Warum*, damit der User lernt.
3. **Quellcode & `.ps1`-Aufrufe zeigen:** Zeige bei Code-Änderungen immer den Quellcode direkt in deiner Antwort und nenne die zugehörigen `.ps1`-Befehle.
4. **Keine automatischen Commits & Pushes:** Führe niemals ungefragt `git commit` oder `git push` aus.