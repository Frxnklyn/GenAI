## Wie man Agenten automatisch testet

*(am Beispiel des Log Analyzer Agents)*

### 1. Was ist ein Agent in Power UI

Ein Agent ist eine KI Unterstützung in deiner Power UI App.
Er bekommt einen Starttext mit Regeln und Hintergrundwissen. Diesen Starttext nennen wir **System Prompt**.

Der System Prompt besteht aus zwei Teilen (siehe Bild 1):

* **Instructions**
  Kurze Beschreibung, wie der Agent sich verhalten soll
  Beispiel: „Du bist ein Support Assistent, der Fehler im Log erklärt“

* **Concepts**
  Das sind Bausteine, die Power UI automatisch einfügt.
  Beispiele:

    * eine Einleitung aus der Dokumentation
    * der Inhalt eines Log Eintrags
    * Code aus der Entwicklerdokumentation

In den Instructions stehen Platzhalter in eckigen Klammern, zum Beispiel `[ #Errorlog# ]`.
Beim Start des Agents ersetzt Power UI diese Platzhalter durch die passenden Concepts.

Wichtig:
Manche Concepts beziehen sich auf Daten, die später gelöscht werden, zum Beispiel alte Log Einträge. Diese Daten nennt man vergänglich.

### 2. Manuell testen

Bevor du automatisch testest, probierst du den Agenten einmal ganz normal aus:

1. Agent in Power UI öffnen
2. Einen **User Prompt** eingeben, zum Beispiel:
   „Help me to find out, what is the Error.“
3. Die Antwort des Agents prüfen

Wenn diese manuelle Anfrage gut funktioniert, kannst du daraus einen automatischen Test bauen.

### 3. Automatischen Test anlegen

Wechsle in den Bereich für Testfälle und lege einen neuen Test an (siehe Bild 2):

1. **Name** vergeben, zum Beispiel „Log Analyzer Input Error“
2. Den passenden **AI Agent** auswählen
3. In das Feld **Test prompt** den Text eintragen, den du vorher manuell verwendet hast
   Beispiel:
   `Help me to find out, what is the Error.`

Damit weiß der automatische Test, welche Frage an den Agent gestellt werden soll.

### 4. Vergängliche Daten über sample_concepts nachladen

Wenn der Agent im System Prompt zum Beispiel einen Log Eintrag über ein Concept lädt, kann es sein, dass dieser Log Eintrag später im System gelöscht wird.
Damit dein Test trotzdem immer mit denselben Daten arbeitet, kannst du diese Daten im Test hinterlegen.

Dafür gibt es im Testkontext den Bereich **sample_concepts** (siehe Bild 3):

* Du legst dort Einträge mit den gleichen Namen an wie die Concepts im Agent
  Beispiel: `errorlog`
* Unter diesem Namen trägst du den Text ein, der testweise verwendet werden soll
  Zum Beispiel den kompletten Fehlertext aus einem Log

Der Agent bekommt im Test dann genau diese Daten, auch wenn der echte Log Eintrag im System nicht mehr vorhanden ist.

### 5. Concepts aus einer bestehenden Conversation übernehmen

Am einfachsten findest du die passenden Inhalte für sample_concepts über eine echte Unterhaltung mit dem Agenten:

1. Öffne den Tab **Conversation**
2. Lade die Unterhaltungen mit dem gewünschten Agent
3. Suche die Unterhaltung, die du manuell getestet hast
4. Klicke diese Unterhaltung an

In der Conversation siehst du mehrere Nachrichten:

* Die **erste Nachricht** ist der System Prompt. Dort steht bei „Role“ oder „Type“ in der Regel „System“.
* Wenn du diese Nachricht öffnest, kannst du den kompletten gerenderten System Prompt lesen.

Unter dieser System Nachricht gibt es einen Bereich **Data** (siehe Bild 4):

* Dort findest du alle verwendeten Concepts
* Jedes Concept hat einen Namen und einen Bereich **output**
* Im output stehen genau die Daten, die der Agent beim manuellen Test gesehen hat

So kopierst du die Daten:

1. Concept öffnen
2. Im Feld output den Text mit der Maus markieren
3. Zwei mal langsam klicken oder alles markieren
4. `Strg` + `C` drücken, um den Text zu kopieren

Diesen Text fügst du im Test in das passende Feld in **sample_concepts** ein.
Wichtig ist, dass der Name im Test exakt so heißt wie der Conceptname in der Conversation.

### 6. Kontext im Test speichern

Wenn du

* den Testprompt eingetragen hast
* und die nötigen sample_concepts ergänzt hast

kannst du den Test speichern.
Beim erneuten Öffnen ist der Kontext vollständig vorbereitet und der Agent bekommt die gleiche Ausgangssituation wie bei deinem manuellen Test.

### 7. Nächste Schritte: Kriterien und Metriken

Nachdem der Kontext steht, kannst du im unteren Bereich **Test criteria** anlegen (siehe Bild 2):

* Jedes Kriterium beschreibt, was an der Antwort geprüft werden soll
  zum Beispiel: „Erklärt der Agent, welches Attribut im Log fehlt“
* Zu jedem Kriterium kannst du **Metriken** hinterlegen
  diese erzeugen eine automatische Bewertung der Antwort

Wie man Kriterien und Metriken im Detail aufbaut, ist der nächste Schritt und kann in einem eigenen Abschnitt erklärt werden.

Mit diesem Ablauf kannst du aus einem einmal manuell getesteten Agenten einen stabilen, wiederholbaren und automatisierten Test erstellen.