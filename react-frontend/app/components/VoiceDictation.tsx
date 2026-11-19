'use client';

import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

/** Minimal typing of the Web Speech API (not in every TypeScript DOM lib). */
interface SpeechResult {
  isFinal: boolean;
  0: { transcript: string };
}
interface SpeechEvent {
  results: ArrayLike<SpeechResult>;
}
interface Recognition {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((event: SpeechEvent) => void) | null;
  onerror: ((event: { error: string }) => void) | null;
  onend: (() => void) | null;
  start(): void;
  stop(): void;
}
type RecognitionConstructor = new () => Recognition;

function recognitionConstructor(): RecognitionConstructor | null {
  const speechWindow = window as unknown as { SpeechRecognition?: RecognitionConstructor; webkitSpeechRecognition?: RecognitionConstructor };

  return speechWindow.SpeechRecognition ?? speechWindow.webkitSpeechRecognition ?? null;
}

const noSubscribe = () => () => {};
const ERROR_MESSAGES: Record<string, string> = {
  'not-allowed': 'Autorisez l’accès au microphone pour dicter.',
  'no-speech': 'Aucune parole détectée. Réessayez.',
  'audio-capture': 'Aucun microphone détecté.',
};

/**
 * Dictation of a clinical note with the browser's speech recognition (French). The recognised
 * text is appended to the field, never replaces it; the doctor still reads and corrects it.
 */
export function VoiceDictation({ onText, label = 'Dicter' }: { onText: (text: string) => void; label?: string }) {
  // Server render has no window: the button stays hidden there, then appears on the client.
  const supported = useSyncExternalStore(noSubscribe, () => recognitionConstructor() !== null, () => false);
  const [listening, setListening] = useState(false);
  const [error, setError] = useState('');
  const recognition = useRef<Recognition | null>(null);

  useEffect(() => () => recognition.current?.stop(), []);

  if (!supported) return null;

  const toggle = () => {
    if (listening) {
      recognition.current?.stop();
      return;
    }
    const Constructor = recognitionConstructor();
    if (!Constructor) return;
    const instance = new Constructor();
    instance.lang = 'fr-FR';
    instance.continuous = true;
    instance.interimResults = false;
    instance.onresult = (event) => {
      let text = '';
      for (let index = 0; index < event.results.length; index++) {
        const result = event.results[index];
        if (result.isFinal) text += result[0].transcript;
      }
      if (text.trim()) onText(text.trim());
    };
    instance.onerror = (event) => setError(ERROR_MESSAGES[event.error] ?? 'La dictée a été interrompue.');
    instance.onend = () => {
      recognition.current = null;
      setListening(false);
    };
    recognition.current = instance;
    setError('');
    instance.start();
    setListening(true);
  };

  return (
    <div className="flex flex-col items-end gap-1">
      <button
        type="button"
        onClick={toggle}
        aria-pressed={listening}
        className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition ${listening ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}
      >
        <span aria-hidden="true" className={`h-2 w-2 rounded-full ${listening ? 'animate-pulse bg-rose-600' : 'bg-slate-400'}`} />
        {listening ? 'Arrêter la dictée' : label}
      </button>
      {error && <span role="alert" className="text-xs text-rose-700">{error}</span>}
    </div>
  );
}
