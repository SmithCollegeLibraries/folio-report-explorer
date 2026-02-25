import { useState, useRef, useEffect } from 'react';
import { MessageSquare, Send, Loader2, Table2, X, Code2, ChevronDown, ChevronUp } from 'lucide-react';
import { askSchema } from '../api/client';

interface Props {
  selectedTable: string | null;
  onNavigateTable: (name: string) => void;
}

interface Message {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  recommendedTables?: string[];
  sql?: string;
}

const EXAMPLE_QUESTIONS = [
  'What tables should I use for circulation statistics?',
  'How do I join items to their holdings and instances?',
  'Which tables contain fund and budget information?',
  'How are purchase orders related to invoices?',
];

export default function SchemaAssistant({ selectedTable, onNavigateTable }: Props) {
  const [isOpen, setIsOpen] = useState(false);
  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [nextId, setNextId] = useState(1);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  // Scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Auto-focus input when panel opens
  useEffect(() => {
    if (isOpen) inputRef.current?.focus();
  }, [isOpen]);

  const handleSubmit = async (q?: string) => {
    const text = q || question.trim();
    if (!text || isLoading) return;

    const userMsg: Message = { id: nextId, role: 'user', content: text };
    setMessages(prev => [...prev, userMsg]);
    setNextId(prev => prev + 1);
    setQuestion('');
    setIsLoading(true);

    try {
      const result = await askSchema(text, selectedTable);
      const assistantMsg: Message = {
        id: nextId + 1,
        role: 'assistant',
        content: result.answer,
        recommendedTables: result.recommendedTables,
        sql: result.sql,
      };
      setMessages(prev => [...prev, assistantMsg]);
      setNextId(prev => prev + 2);
    } catch (err) {
      const errMsg: Message = {
        id: nextId + 1,
        role: 'assistant',
        content: `Sorry, I encountered an error: ${err instanceof Error ? err.message : 'Unknown error'}`,
      };
      setMessages(prev => [...prev, errMsg]);
      setNextId(prev => prev + 2);
    } finally {
      setIsLoading(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit();
    }
  };

  if (!isOpen) {
    return (
      <button
        onClick={() => setIsOpen(true)}
        className="fixed bottom-6 right-6 flex items-center gap-2 bg-folio-600 text-white px-4 py-3 rounded-full shadow-lg hover:bg-folio-700 transition-colors z-50"
        title="Ask about the schema"
      >
        <MessageSquare size={20} />
        <span className="text-sm font-medium">Schema AI</span>
      </button>
    );
  }

  return (
    <div className="fixed bottom-6 right-6 w-[420px] max-h-[600px] bg-white rounded-xl shadow-2xl border flex flex-col z-50">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 bg-folio-600 text-white rounded-t-xl">
        <div className="flex items-center gap-2">
          <MessageSquare size={18} />
          <span className="font-medium text-sm">Schema Assistant</span>
          {selectedTable && (
            <span className="text-xs bg-folio-700 px-2 py-0.5 rounded-full truncate max-w-[180px]">
              {selectedTable}
            </span>
          )}
        </div>
        <button onClick={() => setIsOpen(false)} className="hover:bg-folio-700 rounded p-1 transition-colors">
          <X size={16} />
        </button>
      </div>

      {/* Messages area */}
      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3 min-h-[200px] max-h-[400px]">
        {messages.length === 0 ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-500">
              Ask me anything about the FOLIO schema — tables, columns, relationships, or how to write queries.
            </p>
            <div className="space-y-1.5">
              {EXAMPLE_QUESTIONS.map((q, i) => (
                <button
                  key={i}
                  onClick={() => handleSubmit(q)}
                  className="w-full text-left text-xs bg-gray-50 hover:bg-folio-50 text-gray-600 hover:text-folio-700 px-3 py-2 rounded-md transition-colors"
                >
                  {q}
                </button>
              ))}
            </div>
          </div>
        ) : (
          messages.map((msg) => (
            <div key={msg.id} className={msg.role === 'user' ? 'flex justify-end' : ''}>
              {msg.role === 'user' ? (
                <div className="bg-folio-50 text-folio-800 px-3 py-2 rounded-lg text-sm max-w-[85%]">
                  {msg.content}
                </div>
              ) : (
                <AssistantMessage
                  message={msg}
                  onNavigateTable={onNavigateTable}
                />
              )}
            </div>
          ))
        )}
        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-gray-400">
            <Loader2 size={16} className="animate-spin" />
            Thinking…
          </div>
        )}
        <div ref={messagesEndRef} />
      </div>

      {/* Input area */}
      <div className="border-t px-3 py-3">
        <div className="flex gap-2 items-end">
          <textarea
            ref={inputRef}
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={selectedTable ? `Ask about ${selectedTable}…` : 'Ask about the schema…'}
            rows={1}
            className="flex-1 resize-none border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-folio-500 max-h-20"
          />
          <button
            onClick={() => handleSubmit()}
            disabled={!question.trim() || isLoading}
            className="p-2 bg-folio-600 text-white rounded-lg hover:bg-folio-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex-shrink-0"
          >
            <Send size={16} />
          </button>
        </div>
      </div>
    </div>
  );
}

/** Render an assistant message with markdown-like formatting, tables, and SQL */
function AssistantMessage({
  message,
  onNavigateTable,
}: {
  message: Message;
  onNavigateTable: (name: string) => void;
}) {
  const [showSql, setShowSql] = useState(false);

  return (
    <div className="space-y-2">
      <div className="text-sm text-gray-700 leading-relaxed prose prose-sm max-w-none">
        <FormattedText text={message.content} />
      </div>

      {/* Recommended tables */}
      {message.recommendedTables && message.recommendedTables.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {message.recommendedTables.map((table) => (
            <button
              key={table}
              onClick={() => onNavigateTable(table)}
              className="flex items-center gap-1 text-xs bg-folio-50 text-folio-700 px-2 py-1 rounded-md hover:bg-folio-100 transition-colors"
            >
              <Table2 size={11} />
              {table}
            </button>
          ))}
        </div>
      )}

      {/* SQL snippet */}
      {message.sql && (
        <div>
          <button
            onClick={() => setShowSql(!showSql)}
            className="flex items-center gap-1 text-xs text-gray-500 hover:text-folio-600 transition-colors"
          >
            <Code2 size={12} />
            {showSql ? 'Hide' : 'Show'} SQL
            {showSql ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
          </button>
          {showSql && (
            <pre className="mt-1 bg-gray-900 text-green-400 text-xs p-3 rounded-md overflow-x-auto max-h-48">
              <code>{message.sql}</code>
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

/** Simple markdown-like text formatter */
function FormattedText({ text }: { text: string }) {
  // Split into paragraphs and render with basic formatting
  const lines = text.split('\n');
  const elements: React.ReactNode[] = [];
  let listItems: string[] = [];

  const flushList = () => {
    if (listItems.length > 0) {
      elements.push(
        <ul key={`list-${elements.length}`} className="list-disc pl-4 space-y-0.5">
          {listItems.map((item, i) => (
            <li key={i}>{formatInline(item)}</li>
          ))}
        </ul>
      );
      listItems = [];
    }
  };

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];

    // Headers
    if (line.startsWith('### ')) {
      flushList();
      elements.push(<h4 key={i} className="font-semibold text-gray-800 mt-2">{line.slice(4)}</h4>);
    } else if (line.startsWith('## ')) {
      flushList();
      elements.push(<h3 key={i} className="font-bold text-gray-800 mt-2">{line.slice(3)}</h3>);
    } else if (line.startsWith('# ')) {
      flushList();
      elements.push(<h2 key={i} className="font-bold text-gray-900 mt-2">{line.slice(2)}</h2>);
    }
    // List items
    else if (/^[-*]\s/.test(line)) {
      listItems.push(line.replace(/^[-*]\s/, ''));
    }
    // Numbered list items
    else if (/^\d+\.\s/.test(line)) {
      listItems.push(line.replace(/^\d+\.\s/, ''));
    }
    // Empty line
    else if (line.trim() === '') {
      flushList();
    }
    // Regular paragraph
    else {
      flushList();
      elements.push(<p key={i}>{formatInline(line)}</p>);
    }
  }
  flushList();

  return <>{elements}</>;
}

/** Format inline markdown: **bold**, `code`, *italic* */
function formatInline(text: string): React.ReactNode {
  const parts: React.ReactNode[] = [];
  // Match **bold**, `code`, or *italic*
  const regex = /(\*\*.*?\*\*|`[^`]+`|\*[^*]+\*)/g;
  let lastIdx = 0;
  let match;

  while ((match = regex.exec(text)) !== null) {
    if (match.index > lastIdx) {
      parts.push(text.slice(lastIdx, match.index));
    }
    const m = match[0];
    if (m.startsWith('**') && m.endsWith('**')) {
      parts.push(<strong key={match.index}>{m.slice(2, -2)}</strong>);
    } else if (m.startsWith('`') && m.endsWith('`')) {
      parts.push(
        <code key={match.index} className="bg-gray-100 px-1 py-0.5 rounded text-xs font-mono text-folio-700">
          {m.slice(1, -1)}
        </code>
      );
    } else if (m.startsWith('*') && m.endsWith('*')) {
      parts.push(<em key={match.index}>{m.slice(1, -1)}</em>);
    }
    lastIdx = match.index + m.length;
  }

  if (lastIdx < text.length) {
    parts.push(text.slice(lastIdx));
  }

  return parts.length === 1 ? parts[0] : <>{parts}</>;
}
