(function(){
  // Evita doble init
  if (window.__AM_CONV_INIT__) return;
  window.__AM_CONV_INIT__ = true;

  // Fallbacks if AM_REST/AM_NONCE not defined globally
  const AM_REST = (window.AM_REST || '/wp-json/') + '';
  const AM_NONCE = (window.AM_NONCE || '') + '';
  const deletingCids = new Set();
  function moveChatItemToDateGroup(item, newDateKey) {
    // Find the correct group (h5 with date label)
    const container = item.closest('.am-assistant-chats-container');
    if (!container) return;
    let groupHeader = Array.from(container.querySelectorAll('h5')).find(h => h.textContent === newDateKey);
    let groupList;
    if (!groupHeader) {
      // Create new group if not exists
      groupHeader = document.createElement('h5');
      groupHeader.textContent = newDateKey;
      groupList = document.createElement('ul');
      groupList.className = 'am-chat-list';
      // Insert at top
      container.insertBefore(groupHeader, container.querySelector('h5'));
      container.insertBefore(groupList, groupHeader.nextSibling);
    } else {
      groupList = groupHeader.nextElementSibling;
    }
    groupList.appendChild(item);
  }

  function getTodayLabel() {
    return 'Today';
  }

  function getDateLabel(dateStr) {
    // Use same logic as PHP am_format_date_group
    const today = new Date();
    const d = new Date(dateStr);
    const diffDays = Math.floor((today - d) / 86400000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays <= 7) return diffDays + ' days ago';
    return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function initContainer(cont) {
    if (!cont || cont.__amBound) return;
    cont.__amBound = true;

    // Listen for conversation updates
    window.addEventListener('am:conversation-updated', (e) => {
      const { cid, title, agentId, avatarUrl } = e.detail;
      const item = cont.querySelector(`.am-chat-item[data-conv-uid="${cid}"]`);
      
      if (item) {
        const todayLabel = getTodayLabel(); // retorna 'Today'
        const header = Array.from(cont.querySelectorAll('h5'))
          .find(h => h.textContent.trim().toLowerCase() === todayLabel.toLowerCase());
        if (!header) {
          // si no existe sección "Today", créala al vuelo
          ensureTodaySection(cont);
        }
        updateConversationTimestamp(item);
      } else {
        // Create new conversation item if doesn't exist
        const newItem = createConversationItem({
          public_id: cid,
          agent_id: agentId,
          title: title || 'New conversation',
          avatar_url: avatarUrl
        });
        
        // Add to Today section
        const todayList = ensureTodaySection(cont);
        todayList.insertBefore(newItem, todayList.firstChild);
      }
    });

    cont.addEventListener('click', async (e)=>{
      // Menu toggle
      const menuBtn = e.target.closest('.am-chat-menu-btn');
      if (menuBtn) {
        e.stopPropagation();
        const menu = menuBtn.nextElementSibling;
        if (menu) {
          // Close other open menus first
          cont.querySelectorAll('.am-chat-menu.open').forEach(m => {
            if (m !== menu) m.classList.remove('open');
          });
          menu.classList.toggle('open');
        }
        return;
      }

      // Close menu when clicking outside
      if (!e.target.closest('.am-chat-menu')) {
        cont.querySelectorAll('.am-chat-menu.open').forEach(m => m.classList.remove('open'));
      }

      // Rename chat
      const renameBtn = e.target.closest('.am-rename-btn');
      if (renameBtn) {
        e.stopPropagation();
        const item = renameBtn.closest('.am-chat-item');
        const cid = item?.dataset?.convUid;
        if (!cid) return;

        // Close menu after clicking
        renameBtn.closest('.am-chat-menu')?.classList.remove('open');

        const titleEl = item.querySelector('.am-chat-name a');
        const currentTitle = titleEl ? titleEl.textContent.trim() : '';
        const newTitle = prompt('New title:', currentTitle || 'Chat');
        if (!newTitle || newTitle.trim() === '') return;

        try {
          const r = await fetch(window.AM_REST + 'am/v1/rename_conversation', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': window.AM_NONCE
            },
            body: JSON.stringify({
              conversation_uid: cid,
              title: newTitle.trim()
            })
          });
          if (!r.ok) throw new Error('API error');
          if (titleEl) titleEl.textContent = newTitle.trim();

          // Move item to "Today" group (simulate updated_at change)
          const item = renameBtn.closest('.am-chat-item');
          const todayLabel = getTodayLabel();
          moveChatItemToDateGroup(item, todayLabel);
        } catch (err) {
          alert('Error renaming chat. Please try again.');
        }
        return;
      }

      // Delete chat  
      const deleteBtn = e.target.closest('.am-delete-btn');
      if (deleteBtn) {
        e.stopPropagation();
        const item = deleteBtn.closest('.am-chat-item');
        const cid = item?.dataset?.convUid;
        if (!cid || deletingCids.has(cid)) return;

        // Close menu after clicking
        deleteBtn.closest('.am-chat-menu')?.classList.remove('open');
        
        // Enhanced delete handling
        async function handleDelete(item, cid) {
          if (!confirm('Are you sure you want to delete this chat?')) return;

          try {
            const r = await fetch(window.AM_REST + 'am/v1/delete_conversation', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.AM_NONCE
              },
              body: JSON.stringify({ conversation_uid: cid })
            });

            if (!r.ok) throw new Error('Delete failed');

            // Remove from list
            item.remove();

            // Check if viewing deleted conversation
            const currentCid = new URL(window.location.href).searchParams.get('cid');
            if (currentCid === cid) {
              // Update URL and redirect
              const url = new URL(window.location.href);
              url.searchParams.delete('cid');
              window.history.replaceState({}, '', url.toString());
              window.location.href = url.toString();
            }

          } catch (err) {
            console.error('Delete error:', err);
            alert('Error deleting conversation');
          }
        }

        if (!confirm('Are you sure you want to delete this chat?')) return;
        deletingCids.add(cid);

        deleteBtn.disabled = true;
        const prevText = deleteBtn.textContent;
        deleteBtn.textContent = 'Deleting...';

        try {
          const r = await fetch(window.AM_REST + 'am/v1/delete_conversation', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': window.AM_NONCE
            },
            body: JSON.stringify({
              conversation_uid: cid
            })
          });
          if (!r.ok) throw new Error('API error');
          
          // Remove from DOM
          item.remove();

          // Check if viewing deleted conversation
          const currentCid = new URL(window.location.href).searchParams.get('cid');
          if (currentCid === cid) {
            // Update URL
            const url = new URL(window.location.href);
            url.searchParams.delete('cid');
            window.history.replaceState({}, '', url.toString());
            
            // Clear chat view
            const chatContainer = document.querySelector('.openai-chat-container');
            if (chatContainer) {
              chatContainer.innerHTML = '<div class="error">This conversation has been deleted.</div>';
            }

            // Redirect after short delay
            //setTimeout(() => {
            //  window.location.href = url.toString();
            //}, 2000);
            window.location.replace(url.toString());
          }
        } catch (err) {
          console.error('Delete error:', err);
          alert('Error deleting chat. Please try again.');
          deleteBtn.disabled = false;
          deleteBtn.textContent = prevText;
        } finally {
          deletingCids.delete(cid);
        }
      }
    });

    // Add this function inside init scope
    function updateConversationTimestamp(item) {
      // Move conversation to Today section
      const todayHeader = Array.from(cont.querySelectorAll('h5')).find(h => h.textContent === 'Today');
      const todayList = todayHeader?.nextElementSibling;
      
      if (!todayHeader) {
        // Create Today section if doesn't exist
        const newHeader = document.createElement('h5');
        newHeader.textContent = 'Today';
        const newList = document.createElement('ul');
        newList.className = 'am-chat-list';
        
        // Insert at top
        const firstHeader = cont.querySelector('h5');
        if (firstHeader) {
          cont.insertBefore(newHeader, firstHeader);
          cont.insertBefore(newList, firstHeader);
          newList.appendChild(item);
        }
      } else if (todayList) {
        todayList.insertBefore(item, todayList.firstChild);
      }
    }

    // Helper to ensure Today section exists
    function ensureTodaySection(container) {
      let todayHeader = Array.from(container.querySelectorAll('h5'))
        .find(h => h.textContent === 'Today');
      
      if (!todayHeader) {
        todayHeader = document.createElement('h5');
        todayHeader.textContent = 'Today';
        const list = document.createElement('ul');
        list.className = 'am-chat-list';
        
        container.insertBefore(todayHeader, container.firstChild);
        container.insertBefore(list, todayHeader.nextSibling);
        return list;
      }
      
      return todayHeader.nextElementSibling;
    }

    // Listen for conversation updates
    window.addEventListener('am:conversation-updated', (e) => {
      const { cid } = e.detail;
      const item = document.querySelector(`.am-chat-item[data-conv-uid="${cid}"]`);
      if (item) updateConversationTimestamp(item);
    });
  }

  // Initial binding
  document.querySelectorAll('.am-assistant-chats-container').forEach(initContainer);

  // Watch for dynamic containers
  const mo = new MutationObserver((muts)=>{
    muts.forEach(m=>{
      m.addedNodes?.forEach(n=>{
        if (n.nodeType !== 1) return;
        if (n.classList?.contains('am-assistant-chats-container')) initContainer(n);
        n.querySelectorAll?.('.am-assistant-chats-container').forEach(initContainer);
      });
    });
  });
  mo.observe(document.documentElement, { childList: true, subtree: true });
})();