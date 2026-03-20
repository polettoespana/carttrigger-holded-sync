# Modifiche in lavorazione — 2026-03-20

Questo file viene rimosso quando si crea il tag di versione.

---

## Da includere nel prossimo tag (post 1.0.4)

### Fix
- Corretto `const GROUP = 'ctholded'` → `'cthls'` in `class-cthls-cron.php` (rimasto dal rename v1.0.3)
- Rimosso filtro per group nelle query `as_next_scheduled_action` — usato solo l'hook name per massima compatibilità
- `plugins_loaded` priority aumentata a 20 per garantire che Action Scheduler sia pronto
- Self-healing in `CTHLS_Cron::init()`: se la sync è abilitata e nessuna azione è schedulata, la crea automaticamente
- Aggiunto pulsante **Reschedule** per forzare la registrazione dell'azione in Action Scheduler

### Enhancement
- Aggiunta opzione **Description source**: scelta tra campo personalizzato (tab Holded Sync) o descrizione completa WooCommerce
- Logging completo del cron pull:
  - `pull_start` — avvio pull (scheduled / manual)
  - `pull_error` — errore API durante il fetch
  - `pull_complete` — fine pull con conteggio prodotti elaborati
  - `pull_create` — nuovo prodotto creato in WC da Holded
  - `pull_update` — prodotto aggiornato in WC da Holded
  - `pull_save_error` — errore salvataggio prodotto in WC
- Legend collassabile **Event reference** nel System log con descrizione di ogni evento (tradotta in it_IT e es_ES)
- Visualizzazione della **prossima esecuzione pianificata** (Action Scheduler) nella card Manual sync
- Traduzioni it_IT e es_ES aggiornate con tutte le nuove stringhe
