import React, { useEffect, useMemo, useState } from 'react';
import './App.css';

const API_BASE = import.meta.env.VITE_DIVE_API || 'http://127.0.0.1:8080/api';

async function apiRequest(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers || {}),
    },
    ...options,
  });

  if (response.status === 204) {
    return null;
  }

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.message || 'Request failed');
    error.status = response.status;
    error.retryAfterMs = payload.retry_after_ms;
    throw error;
  }

  return payload;
}

function TaskForm({ parentId, onCreated }) {
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(event) {
    event.preventDefault();
    if (!title.trim()) {
      return;
    }

    setSaving(true);
    setError('');
    try {
      const payload = await apiRequest('/tasks', {
        method: 'POST',
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
          parent_id: parentId || null,
        }),
      });
      setTitle('');
      setDescription('');
      onCreated(payload.task);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className="task-form" onSubmit={handleSubmit}>
      <input
        aria-label={parentId ? 'New subtask title' : 'New task title'}
        placeholder={parentId ? 'Add a subtask' : 'Add a task'}
        value={title}
        onChange={(event) => setTitle(event.target.value)}
      />
      <textarea
        aria-label={parentId ? 'New subtask description' : 'New task description'}
        placeholder="Notes, constraints, or definition of done"
        value={description}
        onChange={(event) => setDescription(event.target.value)}
      />
      <button type="submit" disabled={saving || !title.trim()}>
        {saving ? 'Adding...' : 'Add'}
      </button>
      {error && <p className="notice error">{error}</p>}
    </form>
  );
}

function TaskRow({ task, selected, onSelect, onToggle }) {
  return (
    <div
      className={`task-row ${selected ? 'selected' : ''}`}
    >
      <input
        aria-label={`Mark ${task.title} complete`}
        checked={Boolean(task.completed)}
        onChange={(event) => onToggle(task, event.target.checked)}
        type="checkbox"
      />
      <button className="task-row-main" type="button" onClick={() => onSelect(task)}>
        <span className={task.completed ? 'done' : ''}>{task.title}</span>
        {task.description && <small>{task.description}</small>}
      </button>
      <span className="count">{task.children_count || 0}</span>
    </div>
  );
}

function TaskDetail({ task, subtasks, onRefresh, onCreated, onToggle }) {
  const [splitting, setSplitting] = useState(false);
  const [notice, setNotice] = useState('');

  async function splitTask() {
    setSplitting(true);
    setNotice('');
    try {
      const payload = await apiRequest(`/tasks/${task.id}/split`, { method: 'POST' });
      onCreated(payload.subtasks || []);
      await onRefresh(task.id);
      setNotice(`Added ${payload.subtasks?.length || 0} subtasks.`);
    } catch (error) {
      if (error.status === 429 && error.retryAfterMs) {
        setNotice(`Mistral is cooling down. Try again in ${Math.ceil(error.retryAfterMs / 1000)} seconds.`);
      } else {
        setNotice(error.message);
      }
    } finally {
      setSplitting(false);
    }
  }

  return (
    <section className="detail-panel">
      <div className="detail-header">
        <div>
          <h2>{task.title}</h2>
          {task.description && <p>{task.description}</p>}
        </div>
        <button type="button" onClick={splitTask} disabled={splitting || task.completed}>
          {splitting ? 'Splitting...' : 'Split with AI'}
        </button>
      </div>

      {notice && <p className="notice">{notice}</p>}

      <TaskForm parentId={task.id} onCreated={() => onRefresh(task.id)} />

      <div className="subtasks">
        <h3>Subtasks</h3>
        {subtasks.length === 0 ? (
          <p className="empty">No subtasks yet.</p>
        ) : (
          subtasks.map((subtask) => (
            <TaskRow
              key={subtask.id}
              task={subtask}
              selected={false}
              onSelect={() => onRefresh(subtask.id)}
              onToggle={onToggle}
            />
          ))
        )}
      </div>
    </section>
  );
}

function App() {
  const [tasks, setTasks] = useState([]);
  const [selectedTask, setSelectedTask] = useState(null);
  const [subtasks, setSubtasks] = useState([]);
  const [error, setError] = useState('');

  const activeTasks = useMemo(
    () => tasks.filter((task) => !task.completed).length,
    [tasks],
  );

  async function loadTasks() {
    const payload = await apiRequest('/tasks');
    setTasks(payload.tasks || []);
  }

  async function loadTask(taskId) {
    const payload = await apiRequest(`/tasks/${taskId}`);
    setSelectedTask(payload.task);
    setSubtasks(payload.subtasks || []);
  }

  useEffect(() => {
    loadTasks().catch((requestError) => setError(requestError.message));
  }, []);

  async function handleCreated(task) {
    await loadTasks();
    if (!selectedTask && task) {
      await loadTask(task.id);
    }
  }

  async function handleToggle(task, completed) {
    const payload = await apiRequest(`/tasks/${task.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ completed }),
    });

    setTasks((current) => current.map((item) => (item.id === task.id ? payload.task : item)));
    if (selectedTask?.id === task.id) {
      setSelectedTask(payload.task);
    }
    setSubtasks((current) => current.map((item) => (item.id === task.id ? payload.task : item)));
  }

  return (
    <main className="app-shell">
      <section className="sidebar">
        <header>
          <h1>Dive</h1>
          <p>{activeTasks} open tasks</p>
        </header>

        <TaskForm onCreated={handleCreated} />

        {error && <p className="notice error">{error}</p>}

        <div className="task-list">
          {tasks.map((task) => (
            <TaskRow
              key={task.id}
              task={task}
              selected={selectedTask?.id === task.id}
              onSelect={(nextTask) => loadTask(nextTask.id)}
              onToggle={handleToggle}
            />
          ))}
        </div>
      </section>

      {selectedTask ? (
        <TaskDetail
          task={selectedTask}
          subtasks={subtasks}
          onRefresh={loadTask}
          onCreated={handleCreated}
          onToggle={handleToggle}
        />
      ) : (
        <section className="detail-panel empty-state">
          <h2>Pick a task to refine it.</h2>
          <p>When a task feels too vague, split it into smaller next actions.</p>
        </section>
      )}
    </main>
  );
}

export default App;
